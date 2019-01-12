<?php
/**
 * @author Piotr Mrowczynski piotr@owncloud.com
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

namespace OCA\Files;

use OCA\Files_Sharing\SharedStorage;
use OCP\Files\FileInfo;
use \OC\Files\Filesystem;


/**
 * Handles restricting for download of files
 */
class ViewOnly {

	/**
	 * @param string[] $pathsToCheck
	 * @return bool
	 */
	public function check($pathsToCheck) {
		// If any of elements cannot be downloaded, prevent whole download
		$canDownload = true;
		foreach ($pathsToCheck as $file) {
			if (Filesystem::is_file($file)) {
				// access to filecache is expensive in the loop
				$fileInfo = Filesystem::getFileInfo($file);
				if (!$this->checkFileInfo($fileInfo)){
					$canDownload = false;
				}
			} elseif (Filesystem::is_dir($file)) {
				// get directory content is rather cheap query
				if (!$this->dirRecursiveCheck($file)) {
					$canDownload = false;
				}
			}
		}
		return $canDownload;
	}

	/**
	 * @param string $dir
	 * @return bool
	 */
	private function dirRecursiveCheck($dir) {
		// If any of elements cannot be downloaded, prevent whole download
		$canDownload = true;
		$files = Filesystem::getDirectoryContent($dir);
		foreach ($files as $file) {
			$filename = $file->getName();
			if ($file->getType() === FileInfo::TYPE_FILE) {
				if (!$this->checkFileInfo($file)) {
					$canDownload = false;
				}
			} elseif ($file->getType() === FileInfo::TYPE_FOLDER) {
				$file = $dir . '/' . $filename;
				return $this->dirRecursiveCheck($file);
			}
		}

		return $canDownload;
	}

	/**
	 * @param FileInfo $fileInfo
	 * @return bool
	 */
	private function checkFileInfo(FileInfo $fileInfo) {
		// Restrict view-only to nodes which are shared
		$storage = $fileInfo->getStorage();
		if (!$storage->instanceOfStorage(SharedStorage::class)) {
			return true;
		}

		// Extract extra permissions
		/** @var \OCA\Files_Sharing\SharedStorage $storage */
		$share = $storage->getShare();

		// Check if read-only and on whether permission can download is both set and disabled.
		$canDownload = $share->getAttributes()->getAttribute('core', 'can-download');
		if ($canDownload !== null && !$canDownload) {
			return false;
		}
		return true;
	}
}
