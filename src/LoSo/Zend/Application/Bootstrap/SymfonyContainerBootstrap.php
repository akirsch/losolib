<?php
use Doctrine\Common\Annotations\AnnotationRegistry;
use LoSo\LosoBundle\DependencyInjection\Compiler\RepositoryDefinitionPass;
use LoSo\LosoBundle\DependencyInjection\Loader\AnnotationLoader;
use LoSo\Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\Loader\IniFileLoader;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\DelegatingLoader;
use Symfony\Component\Config\Loader\LoaderResolver;

/**
 * Extension of the default bootstrap class with Symfony Dependency Injection container integration instead
 * of the default registry container.
 * Support advanced container caching for performance optimization.
 *
 * @category   Zend
 * @package    LoSo_Zend_Application
 * @subpackage Bootstrap
 * @author     Loïc Frering <loic.frering@gmail.com>
 */
class LoSo_Zend_Application_Bootstrap_SymfonyContainerBootstrap extends Zend_Application_Bootstrap_Bootstrap
{
    /**
     * Default container registry index.
     *
     * @var string
     */
    protected static $_registryIndex = 'container';

    /**
     * Does the container has to be cached?
     *
     * @var bool
     */
    protected $_doCache;

    /**
     * Does a cache file already exists?
     *
     * @var bool
     */
    protected $_cacheExists;

    /**
     * File where the cached container has to be written.
     *
     * @var string
     */
    protected $_cacheFile;

    /**
     * Load controllers into service container and cache if necessary.
     * {@inheritdoc}
     */
    public function run()
    {
        $front = $this->getResource('FrontController');
        $default = $front->getDefaultModule();
        if (null === $front->getControllerDirectory($default)) {
            throw new Zend_Application_Bootstrap_Exception(
                'No default controller directory registered with front controller'
            );
        }

        $front->setParam('bootstrap', $this);


        // Load service container if not cached or if we want to cache and cache doesn't esist
        if(!$this->_doCache() || ($this->_doCache() && !$this->_cacheExists())) {
            $this->_loadControllers();
            $repositoryDefinitionPass = new RepositoryDefinitionPass();
            $repositoryDefinitionPass->process($this->getContainer());
        }
        // Cache loaded service container if we want to cache and cache doesn't already exist
        if($this->_doCache() && !$this->_cacheExists()) {
            $this->_cacheContainer();
        }

        $this->_loadHttpContext();
        $container = $this->getContainer();
        if (isset($container->doctrine2)) {
            $container->set('doctrine.orm.entity_manager', $container->doctrine2);
        }

        $request = $container->get('zend.controller.request');
        $response = $container->get('zend.controller.response');


        $response = $front->dispatch($request, $response);
        if ($front->returnResponse()) {
            return $response;
        }
    }

    /**
     * Get Symfony container instead of default registry container.
     * Load container from cache if necessary.
     * {@inheritdoc}
     */
    public function getContainer()
    {
        $options = $this->getOption('bootstrap');

        if(null === $this->_container && $options['container']['type'] == 'symfony') {
            $this->_autoloadAnnotations();
            if ($this->_doCache() && $this->_cacheExists()) {
                $cacheFile = $this->_getCacheFile();
                $cacheName = pathinfo($cacheFile, PATHINFO_FILENAME);
                require_once $cacheFile;
                $this->_container = new $cacheName();
            }
            else {
                $this->_container = new ContainerBuilder();
                $this->_loadContainer();
                if($this->_doCache() && !$this->_cacheExists()) {
                    $this->_cacheContainer();
                }
            }

            Zend_Registry::set(self::getRegistryIndex(), $this->_container);
            Zend_Controller_Action_HelperBroker::addHelper(new LoSo_Zend_Controller_Action_Helper_DependencyInjection());
        }
        return parent::getContainer();
    }

    /**
     * Autoload annotations through Doctrine Common's AnnotationRegistry.
     */
    protected function _autoloadAnnotations()
    {
        AnnotationRegistry::registerAutoloadNamespace('LoSo\LosoBundle\DependencyInjection\Annotations');
    }

    /**
     * Check whether the container has to be cached.
     *
     * @return bool
     */
    protected function _doCache()
    {
        if(null === $this->_doCache) {
            $options = $this->getOption('bootstrap');
            $sfContainerOptions = isset($options['container']['symfony']) ? $options['container']['symfony'] : array();
            $this->_doCache = isset($sfContainerOptions['cache']) ? (bool) $sfContainerOptions['cache'] : false;
        }
        return $this->_doCache;
    }

    /**
     * Check if a cache already exists.
     *
     * @return bool
     */
    protected function _cacheExists()
    {
        if(null === $this->_cacheExists) {
            $cacheFile = $this->_getCacheFile();
            $this->_cacheExists = file_exists($cacheFile);
        }
        return $this->_cacheExists;
    }

    /**
     * Return the file where the cache would be written.
     *
     * @return string
     */
    protected function _getCacheFile()
    {
        if(null === $this->_cacheFile) {
            $options = $this->getOption('bootstrap');
            $sfContainerOptions = isset($options['container']['symfony']) ? $options['container']['symfony'] : array();
            if(isset($sfContainerOptions['cacheFile'])) {
                $cacheFile = $sfContainerOptions['cacheFile'];
            }
            else {
                $cacheFile = sys_get_temp_dir() . '/ServiceContainer.php';
            }

            $this->_cacheFile = $cacheFile;
        }
        return $this->_cacheFile;
    }

    /**
     * Load services from configuration files or paths into the service container.
     *
     * @return void
     */
    protected function _loadContainer()
    {
        $options = $this->getOption('bootstrap');
        $sfContainerOptions = isset($options['container']['symfony']) ? $options['container']['symfony'] : array();

        $container = $this->getContainer();

        // First: load configuration paths for annotated classes
        if(isset($sfContainerOptions['configPaths'])) {
            foreach($sfContainerOptions['configPaths'] as $path) {
                $this->_loadPath($path);
            }
        }
        // Then: load configuration files
        if(isset($sfContainerOptions['configFiles'])) {
            foreach($sfContainerOptions['configFiles'] as $file) {
                $this->_loadConfigFile($file);
            }
        }
    }

    /**
     * Load controllers into the service container for lifecycle and dependency management.
     *
     * @return void
     */
    protected function _loadControllers()
    {
        $container = $this->getContainer();

        // Load controllers into service container
        $loader = new AnnotationLoader($container);
        $front = $this->getResource('FrontController');
        $controllerDirectories = $front->getControllerDirectory();
        foreach ($controllerDirectories as $controllerDirectory) {
            $loader->load($controllerDirectory);
        }
    }

    /**
     * Load HTTP context into the service container.
     *
     * @return void
     */
    protected function _loadHttpContext()
    {
        $container = $this->getContainer();
        $request = new Zend_Controller_Request_Http();
        $response = new Zend_Controller_Response_Http();
        $params = array('bootstrap' => $this);
        $container->set('zend.controller.request', $request);
        $container->set('zend.controller.response', $response);
        $container->set('zend.controller.params', $params);
    }

    /**
     * Dump the service container into a plain PHP cached container file.
     *
     * @return void
     */
    protected function _cacheContainer()
    {
        $cacheFile = $this->_getCacheFile();
        $cacheName = pathinfo($cacheFile, PATHINFO_FILENAME);
        $dumper = new PhpDumper($this->getContainer());
        file_put_contents($cacheFile, $dumper->dump(array('class' => $cacheName)));
    }

    /**
     * Load a particular config file, XML, YAML or INI, into the service container.
     *
     * @param  string $file A configuration file
     * @return ContainerBuilder
     */
    protected function _loadConfigFile($file)
    {
        $container = $this->getContainer();
        $locator = new FileLocator($this);
        $resolver = new LoaderResolver(array(
            new XmlFileLoader($container, $locator),
            new YamlFileLoader($container, $locator),
            new IniFileLoader($container, $locator),
            new PhpFileLoader($container, $locator),
        ));

        $loader = new DelegatingLoader($resolver);
        $loader->load($file);
    }

    /**
     * Load classes in a particular path into the service container thanks to the annotation loader.
     *
     * @param  string $path A path with annotated classes
     * @return ContainerBuilder
     */
    protected function _loadPath($path)
    {
        $container = $this->getContainer();
        $loader = new AnnotationLoader($container);
        return $loader->load($path);
    }

    /**
     * Get container's registry index.
     *
     * @return string
     */
    public static function getRegistryIndex()
    {
        return self::$_registryIndex;
    }

    /**
     * Set container's registry index.
     *
     * @param  string $registryIndex
     * @return LoSo_Zend_Application_Bootstrap_SymfonyContainerBootstrap
     */
    public static function setRegistryIndex($registryIndex)
    {
        self::$_registryIndex = $registryIndex;
        return $this;
    }
}
