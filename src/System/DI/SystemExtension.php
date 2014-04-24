<?php

/**
 * This file is part of the Venne:CMS (https://github.com/Venne)
 *
 * Copyright (c) 2011, 2012 Josef Kříž (http://www.josef-kriz.cz)
 *
 * For the full copyright and license information, please view
 * the file license.txt that was distributed with this source code.
 */

namespace Venne\System\DI;

use Kdyby\Doctrine\DI\IEntityProvider;
use Nette\Application\Routers\Route;
use Nette\DI\CompilerExtension;
use Nette\DI\Statement;

/**
 * @author Josef Kříž <pepakriz@gmail.com>
 */
class SystemExtension extends CompilerExtension implements IEntityProvider, IPresenterProvider
{

	/** @var array */
	public $defaults = array(
		'session' => array(),
		'administration' => array(
			'login' => array(
				'name' => '',
				'password' => ''
			),
			'routePrefix' => '',
			'defaultPresenter' => 'System:Admin:Dashboard',
			'authentication' => array(
				'autologin' => NULL,
				'autoregistration' => NULL,
			),
			'registrations' => array(),
			'theme' => 'venne/venne',
		),
		'website' => array(
			'name' => 'Blog',
			'title' => '%n %s %t',
			'titleSeparator' => '|',
			'keywords' => '',
			'description' => '',
			'author' => '',
			'robots' => 'index, follow',
			'routePrefix' => '',
			'oneWayRoutePrefix' => '',
			'languages' => array(),
			'defaultLanguage' => 'cs',
			'defaultPresenter' => 'Homepage',
			'errorPresenter' => 'Cms:Error',
			'layout' => '@cms/bootstrap',
			'cacheMode' => '',
			'cacheValue' => '10',
			'theme' => '',
		),
		'paths' => array(
			'publicDir' => '%wwwDir%/public',
			'dataDir' => '%appDir%/data',
			'logDir' => '%appDir%/../log',
		),
	);


	/**
	 * Processes configuration data. Intended to be overridden by descendant.
	 * @return void
	 */
	public function loadConfiguration()
	{
		$container = $this->getContainerBuilder();
		$config = $this->getConfig($this->defaults);

		foreach ($config['paths'] as $name => $path) {
			if (!isset($container->parameters[$name])) {
				$container->parameters[$name] = $container->expand($path);
			}
		}

		$this->compiler->parseServices(
			$this->getContainerBuilder(),
			$this->loadFromFile(__DIR__ . '/../../../Resources/config/config.neon')
		);

		foreach ($config['administration']['registrations'] as $key => $values) {
			if (isset($values['name']) && $values['name']) {
				$config['administration']['registrations'][$values['name']] = $values;
				unset($config['administration']['registrations'][$key]);
			}
		}

		$container->addDependency($container->parameters['tempDir'] . '/installed');

		// http
		$httpResponse = $container->getDefinition('httpResponse');
		foreach ($httpResponse->setup as $setup) {
			if ($setup->entity == 'setHeader' && $setup->arguments[0] == 'X-Powered-By') {
				$httpResponse->addSetup('setHeader', array('X-Powered-By', $setup->arguments[1] . ' && Venne'));
			}
		}

		$container->addDefinition($this->prefix('controlVerifier'))
			->setClass('Venne\Security\ControlVerifiers\ControlVerifier');

		$container->addDefinition($this->prefix('controlVerifierReader'))
			->setClass('Venne\Security\ControlVerifierReaders\AnnotationReader');

		$container->getDefinition('user')
			->setClass('Venne\Security\User');

		// http
		$container->getDefinition('httpResponse')
			->addSetup('setHeader', array('X-Powered-By', 'Nette Framework && Venne:Framework'));

		// session
		$session = $container->getDefinition('session');
		foreach ($config['session'] as $key => $val) {
			if ($val) {
				$session->addSetup('set' . ucfirst($key), $val);
			}
		}

		// template
		$container->getDefinition('nette.latte')
			->addSetup('$service->compiler->addMacro(\'cache\', new Venne\Latte\Macros\GlobalCacheMacro(?->compiler))', array('@self'));

		$container->addDefinition($this->prefix('templateConfigurator'))
			->setClass("Venne\Templating\TemplateConfigurator", array('@container', '@nette.latte'));

		// helpers
		$container->addDefinition($this->prefix('helpers'))
			->setClass("Venne\Templating\Helpers");


		// security
		$container->getDefinition('nette.userStorage')
			->setClass('Venne\Security\UserStorage', array('@session', new Statement('@doctrine.dao', array('Venne\Security\LoginEntity')), new Statement('@doctrine.dao', array('Venne\Security\UserEntity'))));

		$container->addDefinition($this->prefix('securityManager'))
			->setClass('Venne\Security\SecurityManager');

		// Application
		$application = $container->getDefinition('application');
		$application->addSetup('$service->errorPresenter = ?', array($config['website']['errorPresenter']));

		$container->addDefinition('authorizatorFactory')
			->setFactory('Venne\Security\AuthorizatorFactory', array(new Statement('@doctrine.dao', array('Venne\Security\RoleEntity')), '@session'))
			->addSetup('setReader');

		$container->getDefinition('packageManager.packageManager')
			->addSetup('$service->onInstall[] = ?->clearPermissionSession', array('@authorizatorFactory'))
			->addSetup('$service->onUninstall[] = ?->clearPermissionSession', array('@authorizatorFactory'));

		$container->addDefinition('authorizator')
			->setClass('Nette\Security\Permission')
			->setFactory('@authorizatorFactory::getPermissionsByUser', array('@user', TRUE));

		$container->addDefinition('authenticator')
			->setClass('Venne\Security\Authenticator', array($config['administration']['login']['name'], $config['administration']['login']['password'], new \Nette\DI\Statement('@doctrine.dao', array('Venne\Security\UserEntity'))));

		// detect prefix
		$prefix = $config['website']['routePrefix'];
		$adminPrefix = $config['administration']['routePrefix'];
		$languages = $config['website']['languages'];
		$prefix = str_replace('<lang>/', '<lang ' . implode('|', $languages) . '>/', $prefix);

		// parameters
		$parameters = array();
		$parameters['lang'] = count($languages) > 1 || $config['website']['routePrefix'] ? NULL : $config['website']['defaultLanguage'];

		// Sitemap
		$container->addDefinition($this->prefix('robotsRoute'))
			->setClass('Nette\Application\Routers\Route', array('robots.txt',
				array('presenter' => 'Cms:Sitemap', 'action' => 'robots', 'lang' => NULL)
			))
			->addTag('route', array('priority' => 999999999));
		$container->addDefinition($this->prefix('sitemapRoute'))
			->setClass('Nette\Application\Routers\Route', array('[lang-<lang>/][page-<page>/]sitemap.xml',
				array('presenter' => 'Cms:Sitemap', 'action' => 'sitemap',)
			))
			->addTag('route', array('priority' => 999999998));

		// Administration
		$presenter = explode(':', $config['administration']['defaultPresenter']);
		unset($presenter[1]);
		$container->addDefinition($this->prefix('adminRoute'))
			->setClass('Venne\System\Routers\AdminRoute', array($presenter, $adminPrefix))
			//->addSetup('inject', array($config['website']['defaultLanguage']))
			//->addSetup('$service->injectLanguageDao($this->getByType("Kdyby\Doctrine\EntityManager")->getDao(?))', array('Venne\Cms\LanguageEntity'))
			->addTag('route', array('priority' => 100000));

		// installation
		if (!$config['administration']['login']['name']) {
			$container->addDefinition($this->prefix('installationRoute'))
				->setClass('Nette\Application\Routers\Route', array('', "Admin:{$config['administration']['defaultPresenter']}:", Route::ONE_WAY))
				->addTag('route', array('priority' => -1));
		}

		// CMS route
//		$container->addDefinition($this->prefix('pageRoute'))
//			->setClass('Venne\System\Content\Routes\PageRoute', array('@container', '@cacheStorage', $prefix, $parameters, $config['website']['languages'], $config['website']['defaultLanguage'])
//			)
//			->addTag('route', array('priority' => 100));

		if ($config['website']['oneWayRoutePrefix']) {
			$container->addDefinition($this->prefix('oneWayPageRoute'))
				->setClass('Venne\System\Content\Routes\PageRoute', array('@container', '@cacheStorage', '@doctrine.checkConnection', $config['website']['oneWayRoutePrefix'], $parameters, $config['website']['languages'], $config['website']['defaultLanguage'], TRUE)
				)
				->addTag('route', array('priority' => 99));
		}

		// File route
		$container->addDefinition($this->prefix('imageRoute'))
			->setClass('Venne\Files\Routers\ImageRoute')
			->addTag('route', array('priority' => 99999999));

		$container->addDefinition($this->prefix('fileRoute'))
			->setClass('Venne\Files\Routers\FileRoute')
			->addTag('route', array('priority' => 99999990));

		$container->addDefinition($this->prefix('administrationManager'))
			->setClass('Venne\System\AdministrationManager', array(
				$config['administration']['routePrefix'],
				$config['administration']['defaultPresenter'],
				$config['administration']['login'],
				$config['administration']['theme']
			));
		//	->addSetup('addSideComponent', array('Content', 'Content', '@system.admin.content.browser.contentSideControlFactory', 'fa fa-file'))
		//	->addSetup('addSideComponent', array('Files', 'Files', '@system.admin.content.browser.filesSideControlFactory', 'fa fa-folder-open'))
		//	->addSetup('addSideComponent', array('Layouts', 'Layouts', '@system.admin.content.browser.layoutsSideControlFactory', 'fa fa-th'))
		//	->addSetup('addSideComponent', array('Templates', 'Templates', '@system.admin.content.browser.templatesSideControlFactory', 'fa fa-file-text'));

		// listeners
//		$container->addDefinition($this->prefix('fileListener'))
//			->setClass('Venne\Files\Listeners\FileListener', array(
//				'@container',
//				$container->parameters['publicDir'] . '/media',
//				$container->parameters['dataDir'] . '/media',
//				'/public/media',
//			))
//			->addTag('kdyby.subscriber');

		$container->addDefinition($this->prefix('authenticationFormFactory'))
			->setArguments(array(new Statement('@system.admin.configFormFactory', array($container->expand('%configDir%/config.neon'), 'system.administration.authentication')), $config['administration']['registrations']))
			->setClass('Venne\System\AdminModule\AuthenticationFormFactory');

		$container->addDefinition($this->prefix('admin.loginPresenter'))
			->setClass('Venne\System\AdminModule\LoginPresenter', array(new Statement('@doctrine.dao', array('Venne\Security\RoleEntity'))))
			->addSetup('$service->setAutologin(?)', array($config['administration']['authentication']['autologin']))
			->addSetup('$service->setAutoregistration(?)', array($config['administration']['authentication']['autoregistration']))
			->addSetup('$service->setRegistrations(?)', array($config['administration']['registrations']))
			->addTag('presenter');

		foreach ($this->compiler->getExtensions('Venne\Assets\DI\AssetsExtension') as $extension) {
			$container->getDefinition($extension->prefix('cssLoaderFactory'))
				->addTag('venne.widget', 'css');

			$container->getDefinition($extension->prefix('jsLoaderFactory'))
				->addTag('venne.widget', 'js');

			break;
		}


		$container->removeDefinition('nette.presenterFactory');
		$presenterFactory = $container->addDefinition($this->prefix('presenterFactory'))
			->setClass('Nette\Application\PresenterFactory', array(
				isset($container->parameters['appDir']) ? $container->parameters['appDir'] : NULL
			));
		foreach ($this->compiler->extensions as $extension) {
			if ($extension instanceof IPresenterProvider) {
				$presenterFactory->addSetup('setMapping', array($extension->getPresenterMapping()));
			}
		}
	}


	public function beforeCompile()
	{
		$this->prepareComponents();

		$this->registerMacroFactories();
		$this->registerHelperFactories();
		$this->registerRoutes();
		$this->registerAdministrationPages();
		$this->registerUsers();
		$this->registerLoginProvider();
	}


	public function afterCompile(\Nette\PhpGenerator\ClassType $class)
	{
		parent::afterCompile($class);

		$initialize = $class->methods['initialize'];

		foreach ($this->getSortedServices('subscriber') as $item) {
			$initialize->addBody('$this->getService("eventManager")->addEventSubscriber($this->getService(?));', array($item));
		}

		$initialize->addBody('$this->parameters[\'baseUrl\'] = rtrim($this->getService("httpRequest")->getUrl()->getBaseUrl(), "/");');
		$initialize->addBody('$this->parameters[\'basePath\'] = preg_replace("#https?://[^/]+#A", "", $this->parameters["baseUrl"]);');
	}


	private function registerRoutes()
	{
		$container = $this->getContainerBuilder();
		$router = $container->getDefinition('router');

		foreach ($this->getSortedServices('route') as $route) {
			$definition = $container->getDefinition($route);
			$definition->setAutowired(FALSE);

			$router->addSetup('$service[] = $this->getService(?)', array($route));
		}
	}


	private function registerMacroFactories()
	{
		$container = $this->getContainerBuilder();
		$config = $container->getDefinition($this->prefix('templateConfigurator'));

		foreach ($container->findByTag('macro') as $factory => $meta) {
			$config->addSetup('addFactory', array($factory));
		}
	}


	private function registerHelperFactories()
	{
		$container = $this->getContainerBuilder();
		$config = $container->getDefinition($this->prefix('helpers'));

		foreach ($container->findByTag('helper') as $factory => $meta) {
			$config->addSetup('addHelper', array($meta, "@{$factory}"));
		}
	}


	private function prepareComponents()
	{
		$container = $this->getContainerBuilder();

		foreach ($container->findByTag('component') as $name => $item) {
			$definition = $container->getDefinition($name);
			$definition->setAutowired(FALSE);
		}
	}


	private function registerAdministrationPages()
	{
		$container = $this->getContainerBuilder();
		$manager = $container->getDefinition($this->prefix('administrationManager'));

		foreach ($this->getSortedServices('administration') as $item) {
			$tags = $container->getDefinition($item)->tags['administration'];
			$manager->addSetup('addAdministrationPage', array(
				$tags['link'],
				isset($tags['name']) ? $tags['name'] : NULL,
				isset($tags['description']) ? $tags['description'] : NULL,
				isset($tags['category']) ? $tags['category'] : NULL,
			));
		}
	}


	private function registerUsers()
	{
		$container = $this->getContainerBuilder();
		$config = $container->getDefinition($this->prefix('securityManager'));

		foreach ($container->findByTag('user') as $item => $tags) {
			$arguments = $container->getDefinition($item)->factory->arguments;

			$container->getDefinition($item)->factory->arguments = array(
				0 => is_array($tags) ? $tags['name'] : $tags,
				1 => $arguments[0],
			);

			$config->addSetup('addUserType', array("@{$item}"));
		}
	}


	private function registerLoginProvider()
	{
		$container = $this->getContainerBuilder();
		$config = $container->getDefinition($this->prefix('securityManager'));

		foreach ($container->findByTag('loginProvider') as $item => $tags) {
			$class = '\\' . $container->getDefinition($item)->class;
			$type = $class::getType();

			$config->addSetup('addLoginProvider', array($type, "{$item}"));
		}
	}


	/**
	 * @return array
	 */
	public function getEntityMappings()
	{
		return array(
			'Venne\System' => dirname(__DIR__) . '/*Entity.php',
			'Venne\Comments' => dirname(dirname(__DIR__)) . '/Comments/*Entity.php',
		);
	}


	/**
	 * @return array
	 */
	public function getPresenterMapping()
	{
		return array(
			'System' => 'Venne\System\*Module\*Presenter',
		);
	}


	/**
	 * @param $tag
	 * @return array
	 */
	private function getSortedServices($tag)
	{
		$container = $this->getContainerBuilder();

		$items = array();
		$ret = array();
		foreach ($container->findByTag($tag) as $route => $meta) {
			$priority = isset($meta['priority']) ? $meta['priority'] : (int)$meta;
			$items[$priority][] = $route;
		}

		krsort($items);

		foreach ($items as $items2) {
			foreach ($items2 as $item) {
				$ret[] = $item;
			}
		}
		return $ret;
	}

}
