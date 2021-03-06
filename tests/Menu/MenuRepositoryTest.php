<?php
namespace Czim\CmsCore\Test\Menu;

use Czim\CmsCore\Contracts\Auth\AuthenticatorInterface;
use Czim\CmsCore\Contracts\Core\CoreInterface;
use Czim\CmsCore\Contracts\Menu\MenuConfigInterpreterInterface;
use Czim\CmsCore\Contracts\Menu\MenuPermissionsFilterInterface;
use Czim\CmsCore\Contracts\Modules\Data\MenuPresenceInterface;
use Czim\CmsCore\Contracts\Support\Data\MenuLayoutDataInterface;
use Czim\CmsCore\Contracts\Support\Data\MenuPermissionsIndexDataInterface;
use Czim\CmsCore\Menu\MenuRepository;
use Czim\CmsCore\Support\Data\Menu\LayoutData;
use Czim\CmsCore\Support\Data\Menu\PermissionsIndexData;
use Czim\CmsCore\Support\Data\MenuPresence;
use Czim\CmsCore\Support\Enums\MenuPresenceType;
use Czim\CmsCore\Test\CmsBootTestCase;
use Illuminate\Support\Collection;

class MenuRepositoryTest extends CmsBootTestCase
{

    /**
     * Testing menu layout config.
     *
     * @var array
     */
    protected $menuLayoutConfig = [];

    /**
     * @var MenuPresenceInterface[]
     */
    protected $menuLayoutStandard = [];

    /**
     * @var MenuPresenceInterface[]
     */
    protected $menuLayoutStandardFiltered = [];

    /**
     * Whether user should be mocked as admin.
     *
     * @var bool
     */
    protected $isAdmin = false;

    /**
     * Whether to mock enable caching.
     *
     * @var bool
     */
    protected $cacheEnabled = false;

    public function setUp()
    {
        parent::setUp();

        $this->cacheEnabled = false;
    }


    /**
     * @test
     */
    function it_initializes_succesfully()
    {
        $menu = $this->makeMenuRepository();

        $menu->initialize();
    }

    /**
     * @test
     */
    function it_only_initializes_once()
    {
        $menu = new MenuRepository(
            $this->getMockCore(true),
            $this->getMockAuth(),
            $this->getMockConfigInterpreter(),
            $this->getMockPermissionsFilter()
        );

        $menu->initialize();

        $menu->initialize();
    }

    /**
     * @test
     */
    function it_can_be_set_to_ignore_permissions_for_initialization()
    {
        $this->menuLayoutStandardFiltered = [
            $this->getMockBuilder(MenuPresence::class)->getMock()
        ];

        $menu = $this->makeMenuRepository();

        $menu->ignorePermission();

        $menu->initialize();

        // Assert that the filtered version is not used
        $layout = $menu->getMenuLayout();

        static::assertEmpty($layout, 'Ignoring permissions should not return filtered standard layout');
    }

    /**
     * @test
     */
    function it_returns_empty_layout_before_initialization()
    {
        $menu = $this->makeMenuRepository();

        $presences = $menu->getMenuLayout();

        static::assertInstanceOf(Collection::class, $presences);
        static::assertTrue($presences->isEmpty());
    }

    /**
     * @test
     */
    function it_returns_empty_alternative_presences_before_initialization()
    {
        $menu = $this->makeMenuRepository();

        $presences = $menu->getAlternativePresences();

        static::assertInstanceOf(Collection::class, $presences);
        static::assertTrue($presences->isEmpty());
    }

    // ------------------------------------------------------------------------------
    //      Cache
    // ------------------------------------------------------------------------------

    /**
     * @test
     */
    function it_caches_menu_data()
    {
        $this->isAdmin = true;

        $layout = new LayoutData([
            'layout' => [
                'group' => new MenuPresence([
                    'type'        => MenuPresenceType::GROUP,
                    'label'       => 'Test',
                    'children'    => [],
                    'permissions' => 'testing.test',
                ]),
                'some-module',
                'another-module',
            ]
        ]);

        $index = new PermissionsIndexData([
            'index'       => [],
            'permissions' => ['testing.test'],
        ]);

        // Must set up 'real' data for caching serialization
        $menu = new MenuRepository(
            $this->getMockCore(false),
            $this->getMockAuth(),
            $this->getMockConfigInterpreter($layout),
            $this->getMockPermissionsFilter($index)
        );

        $menu->initialize();

        static::assertFalse(file_exists($this->getMenuCachePath()), 'Cache file should not exist before caching');

        $menu->writeCache();

        static::assertTrue(file_exists($this->getMenuCachePath()), 'Cache file should exist after caching');

        // Assert that the cache works:
        // If the data returned is empty, the mocks are used, not the 'real' cached data.
        $menu = $this->makeMenuRepository();
        $menu->initialize();
        static::assertCount(3, $menu->getMenuLayout());

        $this->deleteMenuCacheFile();
    }

    /**
     * @test
     */
    function it_clears_cached_menu_data()
    {
        $menu = $this->makeMenuRepository();
        $menu->initialize();

        $menu->writeCache();

        static::assertTrue(file_exists($this->getMenuCachePath()), 'Cache file should exist before clearing');

        $menu->clearCache();

        static::assertFalse(file_exists($this->getMenuCachePath()), 'Cache file should not exist after clearing');
    }

    
    // ------------------------------------------------------------------------------
    //      Helpers
    // ------------------------------------------------------------------------------

    /**
     * @return MenuRepository
     */
    protected function makeMenuRepository()
    {
        return new MenuRepository(
            $this->getMockCore(false),
            $this->getMockAuth(),
            $this->getMockConfigInterpreter(),
            $this->getMockPermissionsFilter()
        );
    }

    /**
     * @param bool $exactExpects
     * @return CoreInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    protected function getMockCore($exactExpects = false)
    {
        $mock = $this->getMockBuilder(CoreInterface::class)->getMock();

        $mock->expects($exactExpects ? static::once() : static::any())
             ->method('moduleConfig')
             ->willReturnCallback(function ($key, $default = null) {
                 switch ($key) {
                     case 'menu.layout':
                         return [];
                 }

                 return $default;
             });

        return $mock;
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject|AuthenticatorInterface
     */
    protected function getMockAuth()
    {
        $mock = $this->getMockBuilder(AuthenticatorInterface::class)->getMock();

        $mock->method('admin')->willReturn($this->isAdmin);

        return $mock;
    }

    /**
     * @param null $data
     * @return MenuConfigInterpreterInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    protected function getMockConfigInterpreter($data = null)
    {
        $mock = $this->getMockBuilder(MenuConfigInterpreterInterface::class)->getMock();

        $mock->method('interpretLayout')->willReturn($data ?: $this->getMockLayoutData());

        return $mock;
    }

    /**
     * @param null $index
     * @return MenuPermissionsFilterInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    protected function getMockPermissionsFilter($index = null)
    {
        $mock = $this->getMockBuilder(MenuPermissionsFilterInterface::class)->getMock();

        $mock->method('buildPermissionsIndex')->willReturn($index ?: $this->getMockIndex());
        $mock->method('filterLayout')->willReturn($this->getMockLayoutData(true));

        return $mock;
    }

    /**
     * @param bool $filtered
     * @return MenuLayoutDataInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    protected function getMockLayoutData($filtered = false)
    {
        $mock = $this->getMockBuilder(MenuLayoutDataInterface::class)->getMock();

        $layout = $filtered && $this->menuLayoutStandardFiltered
                ?   $this->menuLayoutStandardFiltered
                :   $this->menuLayoutStandard;

        $mock->method('layout')->willReturn($layout);
        $mock->method('setLayout')->willReturn($mock);
        $mock->method('alternative')->willReturn([]);
        $mock->method('setAlternative')->willReturn($mock);

        return $mock;
    }

    /**
     * @return MenuPermissionsIndexDataInterface|\PHPUnit_Framework_MockObject_MockObject
     */
    protected function getMockIndex()
    {
        $mock = $this->getMockBuilder(MenuPermissionsIndexDataInterface::class)->getMock();

        return $mock;
    }

}
