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
namespace OC\Share20;

use OCP\Share\IAttributes;

class ShareAttributes implements IAttributes {

	/** @var array */
	private $attributes;

	public function __construct() {
		$this->attributes = [];
	}

	/**
	 * @inheritdoc
	 */
	public function setAttribute($scope, $key, $enabled) {
		if (!\array_key_exists($scope, $this->attributes)) {
			$this->attributes[$scope] = [];
		}
		$this->attributes[$scope][$key] = $enabled;
	}

	/**
	 * @inheritdoc
	 */
	public function getAttribute($scope, $key) {
		if (\array_key_exists($scope, $this->attributes) &&
			\array_key_exists($key, $this->attributes[$scope])) {
			return $this->attributes[$scope][$key];
		}
		return null;
	}

	/**
	 * @inheritdoc
	 */
	public function getScopes() {
		return \array_keys($this->attributes);
	}

	/**
	 * @inheritdoc
	 */
	public function getKeys($scope) {
		if (!\array_key_exists($scope, $this->attributes)) {
			return [];
		}
		return \array_keys($this->attributes[$scope]);
	}
}
