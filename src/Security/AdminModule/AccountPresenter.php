<?php

/**
 * This file is part of the Venne:CMS (https://github.com/Venne)
 *
 * Copyright (c) 2011, 2012 Josef Kříž (http://www.josef-kriz.cz)
 *
 * For the full copyright and license information, please view
 * the file license.txt that was distributed with this source code.
 */

namespace Venne\Security\AdminModule;

use Kdyby\Doctrine\EntityDao;
use Nette\Application\UI\Presenter;
use Venne\Bridges\Kdyby\DoctrineForms\FormFactoryFactory;
use Venne\Security\DefaultType\RegistrationFormFactory;
use Venne\System\Components\AdminGrid\IAdminGridFactory;
use Venne\System\AdminPresenterTrait;
use Venne\Security\AdminUserFormFactory;
use Venne\Security\DefaultType\IAdminFormFactory;
use Venne\Security\IFormFactory;
use Venne\Security\LoginEntity;
use Venne\Security\SecurityManager;
use Grido\DataSources\ArraySource;
use Grido\DataSources\Doctrine;
use Nette\Http\Session;
use Nette\Utils\Html;
use Venne\Forms\Form;
use Venne\Security\UserEntity;

/**
 * @author Josef Kříž <pepakriz@gmail.com>
 *
 * @secured
 */
class AccountPresenter extends Presenter
{

	use AdminPresenterTrait;

	/** @persistent */
	public $provider;

	/** @var UserRepository */
	private $userDao;

	/** @var LoginRepository */
	private $loginDao;

	/** @var AccountFormFactory */
	private $accountFormFactory;

	/** @var RegistrationFormFactory */
	private $userFormFactory;

	/** @var ProviderFormFactory */
	private $providerFormFactory;

	/** @var SecurityManager */
	private $securityManager;

	/** @var IAdminGridFactory */
	private $adminGridFactory;

	/** @var Session */
	private $session;

	/** @var FormFactoryFactory */
	private $formFactoryFactory;


	/**
	 * @param EntityDao $userDao
	 * @param EntityDao $loginDao
	 * @param RegistrationFormFactory $userFormFactory
	 * @param AccountFormFactory $accountFormFactory
	 * @param ProviderFormFactory $providerFormFactory
	 * @param SecurityManager $securityManager
	 * @param IAdminGridFactory $adminGridFactory
	 * @param Session $session
	 * @param FormFactoryFactory $formFactoryFactory
	 */
	public function __construct(
		EntityDao $userDao,
		EntityDao $loginDao,
		RegistrationFormFactory $userFormFactory,
		AccountFormFactory $accountFormFactory,
		ProviderFormFactory $providerFormFactory,
		SecurityManager $securityManager,
		IAdminGridFactory $adminGridFactory,
		Session $session,
		FormFactoryFactory $formFactoryFactory
	)
	{
		$this->userDao = $userDao;
		$this->loginDao = $loginDao;
		$this->accountFormFactory = $accountFormFactory;
		$this->userFormFactory = $userFormFactory;
		$this->providerFormFactory = $providerFormFactory;
		$this->securityManager = $securityManager;
		$this->adminGridFactory = $adminGridFactory;
		$this->session = $session;
		$this->formFactoryFactory = $formFactoryFactory;
	}


	/**
	 * @secured(privilege="show")
	 */
	public function actionDefault()
	{
	}


	/**
	 * @secured
	 */
	public function actionEdit()
	{
	}


	public function handleConnect($service, $parameters = NULL)
	{
		$login = $this->securityManager->getLoginProviderByName($service);

		if ($parameters) {
			$login->setAuthenticationParameters(json_decode($parameters, TRUE));
		}

		$login->connectWithUser($this->extendedUser->getUser());

		$this->redirect('this', array('provider' => NULL, 'loginTable-id' => NULL, 'loginTable-formName' => NULL));
	}


	public function handleDisconnect($service)
	{
		$user = $this->extendedUser->getUser();

		foreach ($user->getLoginProviders() as $key => $item) {
			if ($item->getType() === $service) {
				$user->getLoginProviders()->remove($key);
				break;
			}
		}

		$this->userDao->save($user);

		$this->redirect('this', array('provider' => NULL));
	}


	public function renderDefault()
	{
		$this->template->securityManager = $this->securityManager;
	}


	protected function createComponentLoginTable()
	{
		$_this = $this;
		$data = array();

		foreach ($this->securityManager->getLoginProviders() as $name) {
			$data[] = array(
				'id' => str_replace(' ', '_', $name),
				'name' => $name,
			);
		}

		$admin = $this->adminGridFactory->create($this->loginDao);

		// columns
		$table = $admin->getTable();
		$table->setModel(new ArraySource($data));
		$table->setTranslator($this->translator);
		$table->addColumnText('name', 'Name')
			->getCellPrototype()->width = '100%';


		/** @var UserEntity $user */
		$user = $this->user->identity;
		$securityManager = $this->securityManager;
		$providerFormFactory = $this->providerFormFactory;

		// actions
		$table->addActionEvent('connect', 'Connect')
			->setCustomRender(function ($entity, $element) use ($securityManager, $user) {
				if ($user->hasLoginProvider($entity['name'])) {
					$element->class[] = 'disabled';
				} else {
					$element->class[] = 'btn-primary';
				}
				return $element;
			});
		$table->getAction('connect')->onClick[] = function ($button, $name) use ($_this, $securityManager, $providerFormFactory, $user) {
			if (!$securityManager->getLoginProviderByName(str_replace('_', ' ', $name))->getFormContainer()) {
				$_this->redirect('connect!', str_replace('_', ' ', $name));
			} else {
				$_this->provider = str_replace('_', ' ', $name);
				$providerFormFactory->setProvider($_this->provider);
			}
		};
		$table->getAction('connect');

		$this->providerFormFactory->setUser($user);
		if ($this->provider) {
			$this->providerFormFactory->setProvider($this->provider);
		}
		$this->providerFormFactory->onSave[] = function (Form $form) use ($_this) {
			$_this->redirect('connect!', array($form['provider']->value, json_encode($form['parameters']->values)));
		};
		$this->providerFormFactory->onSuccess[] = function ($parameters) use ($_this) {
			$_this->redirect('this');
		};
		$form = $admin->createForm($this->providerFormFactory, 'Provider');
		$admin->connectFormWithAction($form, $table->getAction('connect'));


		$table->addActionEvent('disconnect', 'Disconnect')
			->setCustomRender(function ($entity, $element) use ($securityManager, $user) {
				if (!$user->hasLoginProvider($entity['name'])) {
					$element->class[] = 'disabled';
				};
				return $element;
			})
			->setConfirm(function ($entity) {
				return "Really disconnect from '{$entity['name']}'?";
			});
		$table->getAction('disconnect')->onClick[] = function ($button, $name) use ($_this) {
			$_this->handleDisconnect(str_replace('_', ' ', $name));
		};
		$table->getAction('disconnect')->getElementPrototype()->class[] = 'ajax';

		return $admin;
	}


	protected function createComponentTable()
	{
		$session = $this->session;
		$admin = $this->adminGridFactory->create($this->loginDao);

		// columns
		$table = $admin->getTable();
		if ($this->user->identity instanceof UserEntity) {
			$table->setModel(new Doctrine($this->loginDao->createQueryBuilder('a')->andWhere('a.user = :user')->setParameter('user', $this->user->identity)));
		} else {
			$table->setModel(new Doctrine($this->loginDao->createQueryBuilder('a')->andWhere('a.user IS NULL')));
		}
		$table->setTranslator($this->translator);
		$table->addColumnDate('current', 'Current')
			->setCustomRender(function (LoginEntity $entity) use ($session) {
				$el = Html::el('span');
				$el->class[] = 'glyphicon ' . ($session->id == $entity->getSessionId() ? 'glyphicon-ok' : 'glyphicon-remove');
				return $el;
			})
			->getCellPrototype()->width = '10%';
		$table->addColumnDate('created', 'Date')
			->setSortable()
			->getCellPrototype()->width = '40%';
		$table->addColumnText('sessionId', 'Session ID')
			->getCellPrototype()->width = '50%';

		// actions
		$table->addActionEvent('delete', 'Delete')
			->setConfirm(function ($entity) {
				return "Really delete session '{$entity->sessionId}'?";
			})
			->getElementPrototype()->class[] = 'ajax';
		$admin->connectActionAsDelete($table->getAction('delete'));

		return $admin;
	}


	protected function createComponentAccountForm()
	{
		if ($this->user->identity instanceof UserEntity) {
			$formFactory = $this->securityManager
				->getUserTypeByClass($this->user->identity->class)
				->getFrontFormFactory();

			$form = $this->formFactoryFactory
				->create($formFactory)
				->setEntity($this->user->identity->extendedUser)
				->create();
		} else {
			$form = $this->accountFormFactory->create();
		}

		$form->onSuccess[] = $this->accountFormSuccess;
		return $form;
	}


	public function accountFormSuccess()
	{
		$this->flashMessage($this->translator->translate('Account settings has been updated'), 'success');
		$this->redirect('this');
	}
}
