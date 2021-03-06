<?php

/**
 * This file is part of the Venne:CMS (https://github.com/Venne)
 *
 * Copyright (c) 2011, 2012 Josef Kříž (http://www.josef-kriz.cz)
 *
 * For the full copyright and license information, please view
 * the file license.txt that was distributed with this source code.
 */

namespace Venne\System\AdminModule;

use Nette\Application\UI\Presenter;
use Venne\System\Content\Repositories\PageRepository;
use Nette\Diagnostics\Debugger;

/**
 * @author Josef Kříž <pepakriz@gmail.com>
 */
class ErrorPresenter extends Presenter
{

	/** @var PageRepository */
	protected $pageRepository;


	/**
	 * @param PageRepository $pageRepository
	 */
	public function __construct(PageRepository $pageRepository)
	{
		parent::__construct();

		$this->pageRepository = $pageRepository;
	}


	/**
	 * @param  Exception
	 * @return void
	 */
	public function renderDefault($exception)
	{
		if ($this->isAjax()) { // AJAX request? Just note this error in payload.
			$this->payload->error = TRUE;
			$this->terminate();
		}

		$code = $exception->getCode();
		Debugger::log("HTTP code $code: {$exception->getMessage()} in {$exception->getFile()}:{$exception->getLine()}", 'access');

		if (in_array($code, array(403, 404, 500))) {
			$page = $this->pageRepository->findOneBy(array('special' => $code));

			if ($page) {
				$this->forward(':Cms:Pages:Text:Route:', array('routeId' => $page->mainRoute->id, 'pageId' => $page->id));
			}
		}
	}
}

