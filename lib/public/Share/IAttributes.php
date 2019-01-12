<?php
/**
 * @author Piotr Mrowczynski <piotr@owncloud.com>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OCP\Share;

/**
 * Interface IAttributes
 *
 * @package OCP\Share
 * @since 10.2.0
 */
interface IAttributes {

	/**
	 * Sets an attribute. If the key did not exist before it will be created.
	 *
	 * @param string $scope scope
	 * @param string $key key
	 * @param bool $enabled enabled
	 * @since 10.2.0
	 */
	public function setAttribute($scope, $key, $enabled);

	/**
	 * Checks if attribute for given scope id and key is enabled.
	 * If attribute does not exist, returns null
	 *
	 * @param string $scope scope
	 * @param string $key key
	 * @return bool|null
	 * @since 10.2.0
	 */
	public function getAttribute($scope, $key);

	/**
	 * Get all registered scopes for which attributes are set
	 *
	 * @return string[] scope
	 * @since 10.2.0
	 */
	public function getScopes();

	/**
	 * Get all attribute keys for specific scope
	 *
	 * @param string $scope
	 * @return string[]
	 * @since 10.2.0
	 */
	public function getKeys($scope);
}
