<?php
/**
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 *
 * @author Christoph Wurst <christoph@winzerhof-wurst.at>
 * @author Joas Schilling <coding@schilljs.com>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <robin@icewind.nl>
 * @author Roeland Jago Douma <roeland@famdouma.nl>
 * @author Vincent Petry <vincent@nextcloud.com>
 *
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
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 */
namespace OCA\DAV\Tests\unit\Connector\Sabre;

use OC\Files\View;
use OCA\DAV\Connector\Sabre\Directory;
use OCA\DAV\Connector\Sabre\FilesReportPlugin as FilesReportPluginImplementation;
use OCP\App\IAppManager;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IPreview;
use OCP\IRequest;
use OCP\ITagManager;
use OCP\ITags;
use OCP\IUser;
use OCP\IUserSession;
use OCP\SystemTag\ISystemTag;
use OCP\SystemTag\ISystemTagManager;
use OCP\SystemTag\ISystemTagObjectMapper;
use Sabre\DAV\INode;
use Sabre\DAV\Tree;
use Sabre\HTTP\ResponseInterface;

class FilesReportPluginTest extends \Test\TestCase {
	/** @var \Sabre\DAV\Server|\PHPUnit\Framework\MockObject\MockObject */
	private $server;

	/** @var \Sabre\DAV\Tree|\PHPUnit\Framework\MockObject\MockObject */
	private $tree;

	/** @var ISystemTagObjectMapper|\PHPUnit\Framework\MockObject\MockObject */
	private $tagMapper;

	/** @var ISystemTagManager|\PHPUnit\Framework\MockObject\MockObject */
	private $tagManager;

	/** @var ITags|\PHPUnit\Framework\MockObject\MockObject */
	private $privateTags;

	/** @var  \OCP\IUserSession */
	private $userSession;

	/** @var FilesReportPluginImplementation */
	private $plugin;

	/** @var View|\PHPUnit\Framework\MockObject\MockObject **/
	private $view;

	/** @var IGroupManager|\PHPUnit\Framework\MockObject\MockObject **/
	private $groupManager;

	/** @var Folder|\PHPUnit\Framework\MockObject\MockObject **/
	private $userFolder;

	/** @var IPreview|\PHPUnit\Framework\MockObject\MockObject * */
	private $previewManager;

	/** @var IAppManager|\PHPUnit\Framework\MockObject\MockObject * */
	private $appManager;

	protected function setUp(): void {
		parent::setUp();
		$this->tree = $this->getMockBuilder(Tree::class)
			->disableOriginalConstructor()
			->getMock();

		$this->view = $this->getMockBuilder(View::class)
			->disableOriginalConstructor()
			->getMock();

		$this->server = $this->getMockBuilder('\Sabre\DAV\Server')
			->setConstructorArgs([$this->tree])
			->setMethods(['getRequestUri', 'getBaseUri'])
			->getMock();

		$this->server->expects($this->any())
			->method('getBaseUri')
			->willReturn('http://example.com/owncloud/remote.php/dav');

		$this->groupManager = $this->getMockBuilder(IGroupManager::class)
			->disableOriginalConstructor()
			->getMock();

		$this->userFolder = $this->getMockBuilder(Folder::class)
			->disableOriginalConstructor()
			->getMock();

		$this->previewManager = $this->getMockBuilder(IPreview::class)
			->disableOriginalConstructor()
			->getMock();

		$this->appManager = $this->getMockBuilder(IAppManager::class)
			->disableOriginalConstructor()
			->getMock();

		$this->tagManager = $this->createMock(ISystemTagManager::class);
		$this->tagMapper = $this->createMock(ISystemTagObjectMapper::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->privateTags = $this->createMock(ITags::class);
		$privateTagManager = $this->createMock(ITagManager::class);
		$privateTagManager->expects($this->any())
			->method('load')
			->with('files')
			->willReturn($this->privateTags);

		$user = $this->getMockBuilder(IUser::class)
			->disableOriginalConstructor()
			->getMock();
		$user->expects($this->any())
			->method('getUID')
			->willReturn('testuser');
		$this->userSession->expects($this->any())
			->method('getUser')
			->willReturn($user);

		$this->plugin = new FilesReportPluginImplementation(
			$this->tree,
			$this->view,
			$this->tagManager,
			$this->tagMapper,
			$privateTagManager,
			$this->userSession,
			$this->groupManager,
			$this->userFolder,
			$this->appManager
		);
	}

	public function testOnReportInvalidNode(): void {
		$path = 'totally/unrelated/13';

		$this->tree->expects($this->any())
			->method('getNodeForPath')
			->with('/' . $path)
			->willReturn(
				$this->getMockBuilder(INode::class)
					->disableOriginalConstructor()
					->getMock()
			);

		$this->server->expects($this->any())
			->method('getRequestUri')
			->willReturn($path);
		$this->plugin->initialize($this->server);

		$this->assertNull($this->plugin->onReport(FilesReportPluginImplementation::REPORT_NAME, [], '/' . $path));
	}

	public function testOnReportInvalidReportName(): void {
		$path = 'test';

		$this->tree->expects($this->any())
			->method('getNodeForPath')
			->with('/' . $path)
			->willReturn(
				$this->getMockBuilder(INode::class)
					->disableOriginalConstructor()
					->getMock()
			);

		$this->server->expects($this->any())
			->method('getRequestUri')
			->willReturn($path);
		$this->plugin->initialize($this->server);

		$this->assertNull($this->plugin->onReport('{whoever}whatever', [], '/' . $path));
	}

	public function testOnReport(): void {
		$path = 'test';

		$parameters = [
			[
				'name' => '{DAV:}prop',
				'value' => [
					['name' => '{DAV:}getcontentlength', 'value' => ''],
					['name' => '{http://owncloud.org/ns}size', 'value' => ''],
				],
			],
			[
				'name' => '{http://owncloud.org/ns}filter-rules',
				'value' => [
					['name' => '{http://owncloud.org/ns}systemtag', 'value' => '123'],
					['name' => '{http://owncloud.org/ns}systemtag', 'value' => '456'],
				],
			],
		];

		$this->groupManager->expects($this->any())
			->method('isAdmin')
			->willReturn(true);

		$this->tagMapper->expects($this->exactly(2))
			->method('getObjectIdsForTags')
			->withConsecutive(
				['123', 'files'],
				['456', 'files'],
			)
			->willReturnOnConsecutiveCalls(
				['111', '222'],
				['111', '222', '333'],
			);

		$reportTargetNode = $this->getMockBuilder(Directory::class)
			->disableOriginalConstructor()
			->getMock();
		$reportTargetNode->expects($this->any())
			->method('getPath')
			->willReturn('');

		$response = $this->getMockBuilder(ResponseInterface::class)
			->disableOriginalConstructor()
			->getMock();

		$response->expects($this->once())
			->method('setHeader')
			->with('Content-Type', 'application/xml; charset=utf-8');

		$response->expects($this->once())
			->method('setStatus')
			->with(207);

		$response->expects($this->once())
			->method('setBody');

		$this->tree->expects($this->any())
			->method('getNodeForPath')
			->with('/' . $path)
			->willReturn($reportTargetNode);

		$filesNode1 = $this->getMockBuilder(Folder::class)
			->disableOriginalConstructor()
			->getMock();
		$filesNode2 = $this->getMockBuilder(File::class)
			->disableOriginalConstructor()
			->getMock();

		$this->userFolder->expects($this->exactly(2))
			->method('getById')
			->withConsecutive(
				['111'],
				['222'],
			)
			->willReturnOnConsecutiveCalls(
				[$filesNode1],
				[$filesNode2],
			);

		$this->server->expects($this->any())
			->method('getRequestUri')
			->willReturn($path);
		$this->server->httpResponse = $response;
		$this->plugin->initialize($this->server);

		$this->assertFalse($this->plugin->onReport(FilesReportPluginImplementation::REPORT_NAME, $parameters, '/' . $path));
	}

	public function testFindNodesByFileIdsRoot(): void {
		$filesNode1 = $this->getMockBuilder(Folder::class)
			->disableOriginalConstructor()
			->getMock();
		$filesNode1->expects($this->once())
			->method('getName')
			->willReturn('first node');

		$filesNode2 = $this->getMockBuilder(File::class)
			->disableOriginalConstructor()
			->getMock();
		$filesNode2->expects($this->once())
			->method('getName')
			->willReturn('second node');

		$reportTargetNode = $this->getMockBuilder(Directory::class)
			->disableOriginalConstructor()
			->getMock();
		$reportTargetNode->expects($this->any())
			->method('getPath')
			->willReturn('/');

		$this->userFolder->expects($this->exactly(2))
			->method('getById')
			->withConsecutive(
				['111'],
				['222'],
			)
			->willReturnOnConsecutiveCalls(
				[$filesNode1],
				[$filesNode2],
			);

		/** @var \OCA\DAV\Connector\Sabre\Directory|\PHPUnit\Framework\MockObject\MockObject $reportTargetNode */
		$result = $this->plugin->findNodesByFileIds($reportTargetNode, ['111', '222']);

		$this->assertCount(2, $result);
		$this->assertInstanceOf('\OCA\DAV\Connector\Sabre\Directory', $result[0]);
		$this->assertEquals('first node', $result[0]->getName());
		$this->assertInstanceOf('\OCA\DAV\Connector\Sabre\File', $result[1]);
		$this->assertEquals('second node', $result[1]->getName());
	}

	public function testFindNodesByFileIdsSubDir(): void {
		$filesNode1 = $this->getMockBuilder(Folder::class)
			->disableOriginalConstructor()
			->getMock();
		$filesNode1->expects($this->once())
			->method('getName')
			->willReturn('first node');

		$filesNode2 = $this->getMockBuilder(File::class)
			->disableOriginalConstructor()
			->getMock();
		$filesNode2->expects($this->once())
			->method('getName')
			->willReturn('second node');

		$reportTargetNode = $this->getMockBuilder(Directory::class)
			->disableOriginalConstructor()
			->getMock();
		$reportTargetNode->expects($this->any())
			->method('getPath')
			->willReturn('/sub1/sub2');


		$subNode = $this->getMockBuilder(Folder::class)
			->disableOriginalConstructor()
			->getMock();

		$this->userFolder->expects($this->once())
			->method('get')
			->with('/sub1/sub2')
			->willReturn($subNode);

		$subNode->expects($this->exactly(2))
			->method('getById')
			->withConsecutive(
				['111'],
				['222'],
			)
			->willReturnOnConsecutiveCalls(
				[$filesNode1],
				[$filesNode2],
			);

		/** @var \OCA\DAV\Connector\Sabre\Directory|\PHPUnit\Framework\MockObject\MockObject $reportTargetNode */
		$result = $this->plugin->findNodesByFileIds($reportTargetNode, ['111', '222']);

		$this->assertCount(2, $result);
		$this->assertInstanceOf('\OCA\DAV\Connector\Sabre\Directory', $result[0]);
		$this->assertEquals('first node', $result[0]->getName());
		$this->assertInstanceOf('\OCA\DAV\Connector\Sabre\File', $result[1]);
		$this->assertEquals('second node', $result[1]->getName());
	}

	public function testPrepareResponses(): void {
		$requestedProps = ['{DAV:}getcontentlength', '{http://owncloud.org/ns}fileid', '{DAV:}resourcetype'];

		$fileInfo = $this->createMock(FileInfo::class);
		$fileInfo->method('isReadable')->willReturn(true);

		$node1 = $this->getMockBuilder(Directory::class)
			->disableOriginalConstructor()
			->getMock();
		$node2 = $this->getMockBuilder(\OCA\DAV\Connector\Sabre\File::class)
			->disableOriginalConstructor()
			->getMock();

		$node1->expects($this->once())
			->method('getInternalFileId')
			->willReturn('111');
		$node1->expects($this->any())
			->method('getPath')
			->willReturn('/node1');
		$node1->method('getFileInfo')->willReturn($fileInfo);
		$node2->expects($this->once())
			->method('getInternalFileId')
			->willReturn('222');
		$node2->expects($this->once())
			->method('getSize')
			->willReturn(1024);
		$node2->expects($this->any())
			->method('getPath')
			->willReturn('/sub/node2');
		$node2->method('getFileInfo')->willReturn($fileInfo);

		$config = $this->getMockBuilder(IConfig::class)
			->disableOriginalConstructor()
			->getMock();

		$this->server->addPlugin(
			new \OCA\DAV\Connector\Sabre\FilesPlugin(
				$this->tree,
				$config,
				$this->createMock(IRequest::class),
				$this->previewManager,
				$this->createMock(IUserSession::class)
			)
		);
		$this->plugin->initialize($this->server);
		$responses = $this->plugin->prepareResponses('/files/username', $requestedProps, [$node1, $node2]);

		$this->assertCount(2, $responses);

		$this->assertEquals(200, $responses[0]->getHttpStatus());
		$this->assertEquals(200, $responses[1]->getHttpStatus());

		$this->assertEquals('http://example.com/owncloud/remote.php/dav/files/username/node1', $responses[0]->getHref());
		$this->assertEquals('http://example.com/owncloud/remote.php/dav/files/username/sub/node2', $responses[1]->getHref());

		$props1 = $responses[0]->getResponseProperties();
		$this->assertEquals('111', $props1[200]['{http://owncloud.org/ns}fileid']);
		$this->assertNull($props1[404]['{DAV:}getcontentlength']);
		$this->assertInstanceOf('\Sabre\DAV\Xml\Property\ResourceType', $props1[200]['{DAV:}resourcetype']);
		$resourceType1 = $props1[200]['{DAV:}resourcetype']->getValue();
		$this->assertEquals('{DAV:}collection', $resourceType1[0]);

		$props2 = $responses[1]->getResponseProperties();
		$this->assertEquals('1024', $props2[200]['{DAV:}getcontentlength']);
		$this->assertEquals('222', $props2[200]['{http://owncloud.org/ns}fileid']);
		$this->assertInstanceOf('\Sabre\DAV\Xml\Property\ResourceType', $props2[200]['{DAV:}resourcetype']);
		$this->assertCount(0, $props2[200]['{DAV:}resourcetype']->getValue());
	}

	public function testProcessFilterRulesSingle(): void {
		$this->groupManager->expects($this->any())
			->method('isAdmin')
			->willReturn(true);

		$this->tagMapper->expects($this->exactly(1))
			->method('getObjectIdsForTags')
			->withConsecutive(
				['123', 'files']
			)
			->willReturnMap([
				['123', 'files', 0, '', ['111', '222']],
			]);

		$rules = [
			['name' => '{http://owncloud.org/ns}systemtag', 'value' => '123'],
		];

		$this->assertEquals(['111', '222'], $this->invokePrivate($this->plugin, 'processFilterRules', [$rules]));
	}

	public function testProcessFilterRulesAndCondition(): void {
		$this->groupManager->expects($this->any())
			->method('isAdmin')
			->willReturn(true);

		$this->tagMapper->expects($this->exactly(2))
			->method('getObjectIdsForTags')
			->withConsecutive(
				['123', 'files'],
				['456', 'files']
			)
			->willReturnMap([
				['123', 'files', 0, '', ['111', '222']],
				['456', 'files', 0, '', ['222', '333']],
			]);

		$rules = [
			['name' => '{http://owncloud.org/ns}systemtag', 'value' => '123'],
			['name' => '{http://owncloud.org/ns}systemtag', 'value' => '456'],
		];

		$this->assertEquals(['222'], array_values($this->invokePrivate($this->plugin, 'processFilterRules', [$rules])));
	}

	public function testProcessFilterRulesAndConditionWithOneEmptyResult(): void {
		$this->groupManager->expects($this->any())
			->method('isAdmin')
			->willReturn(true);

		$this->tagMapper->expects($this->exactly(2))
			->method('getObjectIdsForTags')
			->withConsecutive(
				['123', 'files'],
				['456', 'files']
			)
			->willReturnMap([
				['123', 'files', 0, '', ['111', '222']],
				['456', 'files', 0, '', []],
			]);

		$rules = [
			['name' => '{http://owncloud.org/ns}systemtag', 'value' => '123'],
			['name' => '{http://owncloud.org/ns}systemtag', 'value' => '456'],
		];

		$this->assertEquals([], array_values($this->invokePrivate($this->plugin, 'processFilterRules', [$rules])));
	}

	public function testProcessFilterRulesAndConditionWithFirstEmptyResult(): void {
		$this->groupManager->expects($this->any())
			->method('isAdmin')
			->willReturn(true);

		$this->tagMapper->expects($this->exactly(1))
			->method('getObjectIdsForTags')
			->withConsecutive(
				['123', 'files'],
				['456', 'files']
			)
			->willReturnMap([
				['123', 'files', 0, '', []],
				['456', 'files', 0, '', ['111', '222']],
			]);

		$rules = [
			['name' => '{http://owncloud.org/ns}systemtag', 'value' => '123'],
			['name' => '{http://owncloud.org/ns}systemtag', 'value' => '456'],
		];

		$this->assertEquals([], array_values($this->invokePrivate($this->plugin, 'processFilterRules', [$rules])));
	}

	public function testProcessFilterRulesAndConditionWithEmptyMidResult(): void {
		$this->groupManager->expects($this->any())
			->method('isAdmin')
			->willReturn(true);

		$this->tagMapper->expects($this->exactly(2))
			->method('getObjectIdsForTags')
			->withConsecutive(
				['123', 'files'],
				['456', 'files'],
				['789', 'files']
			)
			->willReturnMap([
				['123', 'files', 0, '', ['111', '222']],
				['456', 'files', 0, '', ['333']],
				['789', 'files', 0, '', ['111', '222']],
			]);

		$rules = [
			['name' => '{http://owncloud.org/ns}systemtag', 'value' => '123'],
			['name' => '{http://owncloud.org/ns}systemtag', 'value' => '456'],
			['name' => '{http://owncloud.org/ns}systemtag', 'value' => '789'],
		];

		$this->assertEquals([], array_values($this->invokePrivate($this->plugin, 'processFilterRules', [$rules])));
	}

	public function testProcessFilterRulesInvisibleTagAsAdmin(): void {
		$this->groupManager->expects($this->any())
			->method('isAdmin')
			->willReturn(true);

		$tag1 = $this->getMockBuilder(ISystemTag::class)
			->disableOriginalConstructor()
			->getMock();
		$tag1->expects($this->any())
			->method('getId')
			->willReturn('123');
		$tag1->expects($this->any())
			->method('isUserVisible')
			->willReturn(true);

		$tag2 = $this->getMockBuilder(ISystemTag::class)
			->disableOriginalConstructor()
			->getMock();
		$tag2->expects($this->any())
			->method('getId')
			->willReturn('123');
		$tag2->expects($this->any())
			->method('isUserVisible')
			->willReturn(false);

		// no need to fetch tags to check permissions
		$this->tagManager->expects($this->never())
			->method('getTagsByIds');

		$this->tagMapper->expects($this->exactly(2))
			->method('getObjectIdsForTags')
			->withConsecutive(
				['123'],
				['456'],
			)
			->willReturnOnConsecutiveCalls(
				['111', '222'],
				['222', '333'],
			);

		$rules = [
			['name' => '{http://owncloud.org/ns}systemtag', 'value' => '123'],
			['name' => '{http://owncloud.org/ns}systemtag', 'value' => '456'],
		];

		$this->assertEquals(['222'], array_values($this->invokePrivate($this->plugin, 'processFilterRules', [$rules])));
	}


	public function testProcessFilterRulesInvisibleTagAsUser(): void {
		$this->expectException(\OCP\SystemTag\TagNotFoundException::class);

		$this->groupManager->expects($this->any())
			->method('isAdmin')
			->willReturn(false);

		$tag1 = $this->getMockBuilder(ISystemTag::class)
			->disableOriginalConstructor()
			->getMock();
		$tag1->expects($this->any())
			->method('getId')
			->willReturn('123');
		$tag1->expects($this->any())
			->method('isUserVisible')
			->willReturn(true);

		$tag2 = $this->getMockBuilder(ISystemTag::class)
			->disableOriginalConstructor()
			->getMock();
		$tag2->expects($this->any())
			->method('getId')
			->willReturn('123');
		$tag2->expects($this->any())
			->method('isUserVisible')
			->willReturn(false); // invisible

		$this->tagManager->expects($this->once())
			->method('getTagsByIds')
			->with(['123', '456'])
			->willReturn([$tag1, $tag2]);

		$rules = [
			['name' => '{http://owncloud.org/ns}systemtag', 'value' => '123'],
			['name' => '{http://owncloud.org/ns}systemtag', 'value' => '456'],
		];

		$this->invokePrivate($this->plugin, 'processFilterRules', [$rules]);
	}

	public function testProcessFilterRulesVisibleTagAsUser(): void {
		$this->groupManager->expects($this->any())
			->method('isAdmin')
			->willReturn(false);

		$tag1 = $this->getMockBuilder(ISystemTag::class)
			->disableOriginalConstructor()
			->getMock();
		$tag1->expects($this->any())
			->method('getId')
			->willReturn('123');
		$tag1->expects($this->any())
			->method('isUserVisible')
			->willReturn(true);

		$tag2 = $this->getMockBuilder(ISystemTag::class)
			->disableOriginalConstructor()
			->getMock();
		$tag2->expects($this->any())
			->method('getId')
			->willReturn('123');
		$tag2->expects($this->any())
			->method('isUserVisible')
			->willReturn(true);

		$this->tagManager->expects($this->once())
			->method('getTagsByIds')
			->with(['123', '456'])
			->willReturn([$tag1, $tag2]);

		$this->tagMapper->expects($this->exactly(2))
			->method('getObjectIdsForTags')
			->withConsecutive(
				['123'],
				['456'],
			)
			->willReturnOnConsecutiveCalls(
				['111', '222'],
				['222', '333'],
			);

		$rules = [
			['name' => '{http://owncloud.org/ns}systemtag', 'value' => '123'],
			['name' => '{http://owncloud.org/ns}systemtag', 'value' => '456'],
		];

		$this->assertEquals(['222'], array_values($this->invokePrivate($this->plugin, 'processFilterRules', [$rules])));
	}

	public function testProcessFavoriteFilter(): void {
		$rules = [
			['name' => '{http://owncloud.org/ns}favorite', 'value' => '1'],
		];

		$this->privateTags->expects($this->once())
			->method('getFavorites')
			->willReturn(['456', '789']);

		$this->assertEquals(['456', '789'], array_values($this->invokePrivate($this->plugin, 'processFilterRules', [$rules])));
	}

	public function filesBaseUriProvider() {
		return [
			['', '', ''],
			['files/username', '', '/files/username'],
			['files/username/test', '/test', '/files/username'],
			['files/username/test/sub', '/test/sub', '/files/username'],
			['test', '/test', ''],
		];
	}

	/**
	 * @dataProvider filesBaseUriProvider
	 */
	public function testFilesBaseUri($uri, $reportPath, $expectedUri): void {
		$this->assertEquals($expectedUri, $this->invokePrivate($this->plugin, 'getFilesBaseUri', [$uri, $reportPath]));
	}
}
