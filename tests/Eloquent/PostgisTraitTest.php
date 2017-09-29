<?php

use Illuminate\Database\Eloquent\Model;
use Mockery as m;
use Phaza\LaravelPostgis\Eloquent\Builder;
use Phaza\LaravelPostgis\Eloquent\PostgisTrait;
use Phaza\LaravelPostgis\Geometries\Point;
use Phaza\LaravelPostgis\Geometries\Linestring;
use Phaza\LaravelPostgis\Geometries\Polygon;
use Phaza\LaravelPostgis\PostgisConnection;
use Phaza\LaravelPostgis\Exceptions\UndefinedPostgisFieldException;

class PostgisTraitTest extends BaseTestCase
{
    /**
     * @var TestModel
     */
    protected $model;

    /**
     * @var array
     */
    protected $queries;

    public function setUp()
    {
        $this->model = new TestModel();
        $this->queries = &$this->model->getConnection()->getPdo()->queries;
    }

    public function tearDown()
    {
        $this->model->getConnection()->getPdo()->resetQueries();
    }

    public function testInsertPointHasCorrectSql()
    {
        $this->model->point = new Point(1, 2);
        $this->model->save();

        $this->assertContains("public.ST_GeogFromText('POINT(2 1)')", $this->queries[0]);
    }

    public function testInsertPointGeometryHasCorrectSql()
    {
        $this->model->point2 = new Point(1, 2);
        $this->model->save();

        $this->assertContains("public.ST_GeomFromText('POINT(2 1)', '27700')", $this->queries[0]);
    }

    public function testUpdatePointHasCorrectSql()
    {
        $this->model->exists = true;
        $this->model->point = new Point(2, 4);
        $this->model->save();

        $this->assertContains("public.ST_GeogFromText('POINT(4 2)')", $this->queries[0]);
    }

    /* From here on we test spatial query scopes */
    private function buildTestPolygon()
    {
        $point1 = new Point(1, 1);
        $point2 = new Point(1, 2);
        $linestring1 = new LineString([$point1, $point2]);
        $point3 = new Point(1, 2);
        $point4 = new Point(2, 2);
        $linestring2 = new LineString([$point3, $point4]);
        $point5 = new Point(2, 2);
        $point6 = new Point(1, 1);
        $linestring3 = new LineString([$point5, $point6]);
        return new Polygon([$linestring1, $linestring2, $linestring3]);
    }

    public function testExceptionThrownIfNonspatialField()
    {
        $this->setExpectedException(UndefinedPostgisFieldException::class, 'Field undefined_field in class TestModel not present in $postgisFields');

        $query = TestModel::st_contains('undefined_field', $this->buildTestPolygon());
    }

    public function testScopeContains()
    {
        $query = TestModel::st_contains('point', $this->buildTestPolygon());

        $this->assertInstanceOf(Builder::class, $query);
        $q = $query->getQuery();
        $this->assertNotEmpty($q->wheres);
        $this->assertContains("public.ST_Contains(point::geometry, public.ST_GeomFromText('POLYGON((1 1,2 1),(2 1,2 2),(2 2,1 1))', 4326))", $q->wheres[0]['sql']);
    }

    public function testScopeCovers()
    {
        $query = TestModel::st_covers('point', $this->buildTestPolygon());

        $this->assertInstanceOf(Builder::class, $query);
        $q = $query->getQuery();
        $this->assertNotEmpty($q->wheres);
        $this->assertContains("public.ST_Covers(point::geometry, public.ST_GeomFromText('POLYGON((1 1,2 1),(2 1,2 2),(2 2,1 1))', 4326))", $q->wheres[0]['sql']);
    }

    public function testScopeCrosses()
    {
        $query = TestModel::st_crosses('point', $this->buildTestPolygon());

        $this->assertInstanceOf(Builder::class, $query);
        $q = $query->getQuery();
        $this->assertNotEmpty($q->wheres);
        $this->assertContains("public.ST_Crosses(point::geometry, public.ST_GeomFromText('POLYGON((1 1,2 1),(2 1,2 2),(2 2,1 1))', 4326))", $q->wheres[0]['sql']);
    }

    public function testScopeIntersects()
    {
        $query = TestModel::st_intersects('point', $this->buildTestPolygon());

        $this->assertInstanceOf(Builder::class, $query);
        $q = $query->getQuery();
        $this->assertNotEmpty($q->wheres);
        $this->assertContains("public.ST_Intersects(point::geometry, public.ST_GeomFromText('POLYGON((1 1,2 1),(2 1,2 2),(2 2,1 1))', 4326))", $q->wheres[0]['sql']);
    }
}

class TestModel extends Model
{
    use PostgisTrait;

    protected $postgisFields = [
        'point' => Point::class,
        'point2' => Polygon::class,
    ];

    protected $postgisTypes = [
        'point2' => [
            'geomtype' => 'geometry',
            'srid' => 27700
        ]
    ];


    public static $pdo;

    public static function resolveConnection($connection = null)
    {
        if (is_null(static::$pdo)) {
            static::$pdo = m::mock('TestPDO')->makePartial();
        }

        return new PostgisConnection(static::$pdo);
    }

    public function testrelatedmodels()
    {
        return $this->hasMany(TestRelatedModel::class);
    }

    public function testrelatedmodels2()
    {
        return $this->belongsToMany(TestRelatedModel::class);
    }

}

class TestRelatedModel extends TestModel
{
    public function testmodel()
    {
        return $this->belongsTo(TestModel::class);
    }

    public function testmodels()
    {
        return $this->belongsToMany(TestModel::class);
    }
}

class TestPDO extends PDO
{
    public $queries = [];
    public $counter = 1;

    public function prepare($statement, $driver_options = null)
    {
        $this->queries[] = $statement;

        $stmt = m::mock('PDOStatement');
        $stmt->shouldReceive('setFetchMode');
        $stmt->shouldReceive('bindValue')->zeroOrMoreTimes();
        $stmt->shouldReceive('execute');
        $stmt->shouldReceive('fetchAll')->andReturn([['id' => 1, 'point' => 'POINT(1 2)']]);
        $stmt->shouldReceive('rowCount')->andReturn(1);

        return $stmt;
    }

    public function lastInsertId($name = null)
    {
        return $this->counter++;
    }

    public function resetQueries()
    {
        $this->queries = [];
    }
}
