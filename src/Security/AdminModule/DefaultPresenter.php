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

use Grido\DataSources\Doctrine;
use Kdyby\Doctrine\EntityDao;
use Nette\Application\UI\Presenter;
use Venne\Security\DefaultType\AdminFormFactory;
use Venne\Security\SecurityManager;
use Venne\System\AdminPresenterTrait;
use Venne\System\Components\AdminGrid\Form;
use Venne\System\Components\AdminGrid\IAdminGridFactory;

/**
 * @author Josef Kříž <pepakriz@gmail.com>
 *
 * @secured
 */
class DefaultPresenter extends Presenter
{

	use AdminPresenterTrait;

	/** @persistent */
	public $page;

	/** @persistent */
	public $type;

	/** @var EntityDao */
	private $userDao;

	/** @var AdminFormFactory */
	private $form;

	/** @var ProvidersFormFactory */
	private $providersForm;

	/** @var SecurityManager */
	private $securityManager;

	/** @var IAdminGridFactory */
	private $adminGridFactory;


	/**
	 * @param EntityDao $userDao
	 * @param AdminFormFactory $form
	 * @param ProvidersFormFactory $providersForm
	 * @param SecurityManager $securityManager
	 * @param IAdminGridFactory $adminGridFactory
	 */
	public function __construct(
		EntityDao $userDao,
		AdminFormFactory $form,
		ProvidersFormFactory $providersForm,
		SecurityManager $securityManager,
		IAdminGridFactory $adminGridFactory
	)
	{
		$this->userDao = $userDao;
		$this->form = $form;
		$this->providersForm = $providersForm;
		$this->securityManager = $securityManager;
		$this->adminGridFactory = $adminGridFactory;
	}


	/**
	 * @return SecurityManager
	 */
	public function getSecurityManager()
	{
		return $this->securityManager;
	}


	protected function startup()
	{
		parent::startup();

		if (!$this->type) {
			$this->type = key($this->securityManager->getUserTypes());
		}
	}


	protected function createComponentTable()
	{
		$dao = $this->entityManager->getDao($this->type);
		$admin = $this->adminGridFactory->create($dao);
		$table = $admin->getTable();
		$table->setTranslator($this->translator);
		$table->setModel(new Doctrine($dao->createQueryBuilder('a')
				->addSelect('u')
				->innerJoin('a.user', 'u'),
			array('email' => 'u.email')
		));

		// columns
		$table->addColumnText('email', 'E-mail')
			->setCustomRender(function ($entity) {
				return $entity->user->email;
			})
			->setSortable()
			->getCellPrototype()->width = '60%';
		$table->getColumn('email')
			->setFilterText()->setSuggestion();

		$table->addColumnText('roles', 'Roles')
			->getCellPrototype()->width = '40%';
		$table->getColumn('roles')
			->setCustomRender(function ($entity) {
				return implode(", ", $entity->user->roles);
			});

		// actions
		$table->addActionEvent('edit', 'Edit')
			->getElementPrototype()->class[] = 'ajax';

		$table->addActionEvent('loginProviders', 'Login providers')
			->getElementPrototype()->class[] = 'ajax';

		$type = $this->type;
		$form = $admin->createForm($this->getUserType()->getFormFactory(), 'User', function () use ($type) {
			return new $type;
		}, Form::TYPE_LARGE);
		$providerForm = $admin->createForm($this->providersForm, 'Login providers', NULL, Form::TYPE_LARGE);

		$admin->connectFormWithAction($form, $table->getAction('edit'), $admin::MODE_PLACE);
		$admin->connectFormWithAction($providerForm, $table->getAction('loginProviders'));

		// Toolbar
		$toolbar = $admin->getNavbar();
		$toolbar->addSection('new', 'Create', 'file');
		$admin->connectFormWithNavbar($form, $toolbar->getSection('new'), $admin::MODE_PLACE);

		$table->addActionEvent('delete', 'Delete')
			->getElementPrototype()->class[] = 'ajax';
		$admin->connectActionAsDelete($table->getAction('delete'));

		return $admin;
	}


	/**
	 * @return \Venne\Security\UserType
	 */
	private function getUserType()
	{
		return $this->securityManager->getUserTypeByClass($this->type);
	}

}
