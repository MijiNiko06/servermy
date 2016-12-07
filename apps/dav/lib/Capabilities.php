<?php
/**
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Piotr Mrowczynski <piotr@owncloud.com>
 *
 * @copyright Copyright (c) 2016, ownCloud GmbH
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


namespace OCA\DAV;

use OCP\Capabilities\ICapability;

class Capabilities implements ICapability {

	/*
	 * This function will return:
	 *
	 * - <chunking>: version number of chunking on the client
	 *
	 * - <max_single_upload_request_duration_msec>: Dynamic Chunking attribute the maximum number of miliseconds that single request below chunk size can take
	 * 		This value should be based on heuristics with default value 10000ms, time it takes to transfer 10MB chunk on 1MB/s upload link.
	 *
	 * 		Suggested solution will be to evaluate max(SNR, MORD) where:
	 * 	    > SNR - Slow network request, so time it will take to transmit default chunking sized request on the current client version to sync at specific low upload bandwidth
	 *      > MORD - Maximum observed request time, so double the time of maximum observed RTT of the very small PUT request (e.g. 1kB) to the system
	 *
	 * 		Exemplary, syncing 100MB files, with chunking size 10MB, will cause sync of 10 PUT requests which max evaluation was set to <max_single_upload_request_duration_msec>
	 *
	 * 		Dynamic chunking client algorithm is specified in the ownCloud documentation and uses <max_single_upload_request_duration_msec> to estimate if given
	 * 		bandwidth allows higher chunk sizes (because of high goodput)
	 */
	public function getCapabilities() {
		return [
			'dav' => [
				'chunking' => '1.0',
				'max_single_upload_request_duration_msec' => '10000',
			]
		];
	}
}
