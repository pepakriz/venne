<?php

/**
 * This file is part of the Venne:CMS (https://github.com/Venne)
 *
 * Copyright (c) 2011, 2012 Josef Kříž (http://www.josef-kriz.cz)
 *
 * For the full copyright and license information, please view
 * the file license.txt that was distributed with this source code.
 */

namespace Venne\Security\Registration;

/**
 * @author Josef Kříž <pepakriz@gmail.com>
 */
interface IRegistrationControlFactory
{

	/**
	 * @return RegistrationControl
	 */
	function create($invitations, $userType, $mode, $loginProviderMode, $roles);

}
