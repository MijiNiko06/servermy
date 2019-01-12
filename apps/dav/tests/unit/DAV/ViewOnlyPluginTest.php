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
namespace OCA\DAV\Tests\unit\DAV;

use OCA\DAV\DAV\ViewOnlyPlugin;
use OCA\Files_Sharing\SharedStorage;
use OCA\DAV\Connector\Sabre\File as DavFile;
use OCP\Files\FileInfo;
use OCP\Files\Storage\IStorage;
use OCP\Share\IAttributes;
use OCP\Share\IShare;
use Sabre\DAV\Server;
use Sabre\DAV\Tree;
use Test\TestCase;
use Sabre\HTTP\RequestInterface;
use OCA\DAV\Connector\Sabre\Exception\Forbidden;

class ViewOnlyPluginTest extends TestCase {

	/** @var ViewOnlyPlugin */
	private $plugin;
	/** @var Tree | \PHPUnit_Framework_MockObject_MockObject */
	private $tree;
	/** @var RequestInterface | \PHPUnit_Framework_MockObject_MockObject */
	private $request;

	public function setUp() {
		$this->plugin = new ViewOnlyPlugin();
		$this->request = $this->getMockBuilder('Sabre\HTTP\RequestInterface')->getMock();
		$this->tree = $this->createMock(Tree::class);

		$server = $this->createMock(Server::class);
		$server->tree = $this->tree;

		$this->plugin->initialize($server);
	}

	public function testCanGetNonDav() {
		$this->request->expects($this->exactly(1))->method('getPath')->willReturn('files/test/target');
		$this->tree->method('getNodeForPath')->willReturn(null);

		$this->assertTrue($this->plugin->checkViewOnly($this->request));
	}

	public function testCanGetNonFileInfo() {
		$this->request->expects($this->exactly(1))->method('getPath')->willReturn('files/test/target');
		$davNode = $this->createMock(DavFile::class);
		$this->tree->method('getNodeForPath')->willReturn($davNode);

		$davNode->method('getFileInfo')->willReturn(null);

		$this->assertTrue($this->plugin->checkViewOnly($this->request));
	}

	public function testCanGetNonShared() {
		$this->request->expects($this->exactly(1))->method('getPath')->willReturn('files/test/target');
		$davNode = $this->createMock(DavFile::class);
		$this->tree->method('getNodeForPath')->willReturn($davNode);

		$fileInfo = $this->createMock(FileInfo::class);
		$davNode->method('getFileInfo')->willReturn($fileInfo);

		$storage = $this->createMock(IStorage::class);
		$fileInfo->method('getStorage')->willReturn($storage);
		$storage->method('instanceOfStorage')->with(SharedStorage::class)->willReturn(false);

		$this->assertTrue($this->plugin->checkViewOnly($this->request));
	}

	public function nodeReturns() {
		return [
			// can download and is updatable - can get file
			[ $this->createMock(FileInfo::class), true, true, true],
			// extra permission can download is for some reason disabled,
			// but file is updatable - so can get file
			[ $this->createMock(FileInfo::class), false, true, true],
			// has extra permission can download, and read-only is set - can get file
			[ $this->createMock(FileInfo::class), true, false, true],
			// has no extra permission can download, and read-only is set - cannot get the file
			[ $this->createMock(FileInfo::class), false, false, false],
		];
	}

	/**
	 * @dataProvider nodeReturns
	 */
	public function testCanGet($fileInfo, $canDownloadPerm, $isUpdatable, $expected) {
		$this->request->expects($this->exactly(1))->method('getPath')->willReturn('files/test/target');

		$davNode = $this->createMock(DavFile::class);
		$this->tree->method('getNodeForPath')->willReturn($davNode);

		$davNode->method('getFileInfo')->willReturn($fileInfo);

		$storage = $this->createMock(SharedStorage::class);
		$share = $this->createMock(IShare::class);
		$fileInfo->method('getStorage')->willReturn($storage);
		$storage->method('instanceOfStorage')->with(SharedStorage::class)->willReturn(true);
		$storage->method('getShare')->willReturn($share);

		$extPerms = $this->createMock(IAttributes::class);
		$share->method('getAttributes')->willReturn($extPerms);
		$extPerms->method('getAttribute')->with('core', 'can-download')->willReturn($canDownloadPerm);
		$fileInfo->method('isUpdateable')->willReturn($isUpdatable);

		try {
			// with these permissions / with this type of node user can download
			$ret = $this->plugin->checkViewOnly($this->request);
			$this->assertEquals($expected, $ret);
		} catch (Forbidden $e) {
			// this node is share, with read-only and without can-download permission
			$this->assertFalse($expected);
		}
	}
}
