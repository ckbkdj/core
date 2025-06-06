<?php
/**
 * @author Joas Schilling <coding@schilljs.com>
 * @author Thomas Citharel <tcit@tcit.fr>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
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

namespace OCA\DAV\Tests\unit\CalDAV;

use DateTime;
use DateTimeZone;
use OCA\DAV\CalDAV\CalDavBackend;
use OCA\DAV\CalDAV\Calendar;
use OCP\IL10N;
use Sabre\CalDAV\Xml\Property\ScheduleCalendarTransp;
use Sabre\DAV\PropPatch;
use Sabre\DAV\Xml\Property\Href;
use Sabre\DAVACL\IACL;
use Sabre\DAV\Exception\NotFound;

/**
 * Class CalDavBackendTest
 *
 * @group DB
 *
 * @package OCA\DAV\Tests\unit\CalDAV
 */
class CalDavBackendTest extends AbstractCalDavBackendTest {
	/**
	 * @throws \Sabre\DAV\Exception
	 */
	public function testCalendarOperations() {
		$calendarId = $this->createTestCalendar();

		// update it's display name
		$patch = new PropPatch([
			'{DAV:}displayname' => 'Unit test',
			'{urn:ietf:params:xml:ns:caldav}calendar-description' => 'Calendar used for unit testing',
			'{urn:ietf:params:xml:ns:caldav}schedule-calendar-transp' => new ScheduleCalendarTransp('transparent')
		]);
		$this->backend->updateCalendar($calendarId, $patch);
		$patch->commit();
		$books = $this->backend->getCalendarsForUser(self::UNIT_TEST_USER);
		$this->assertCount(1, $books);
		$this->assertEquals('Unit test', $books[0]['{DAV:}displayname']);
		$this->assertEquals('Calendar used for unit testing', $books[0]['{urn:ietf:params:xml:ns:caldav}calendar-description']);
		$this->assertEquals(new ScheduleCalendarTransp('transparent'), $books[0]['{urn:ietf:params:xml:ns:caldav}schedule-calendar-transp']);

		// delete the address book
		$this->backend->deleteCalendar($books[0]['id']);
		$books = $this->backend->getCalendarsForUser(self::UNIT_TEST_USER);
		$this->assertCount(0, $books);
	}

	public function providesSharingData() {
		return [
			[true, true, true, false, [
				[
					'href' => 'principal:' . self::UNIT_TEST_USER1,
					'readOnly' => false
				],
				[
					'href' => 'principal:' . self::UNIT_TEST_GROUP,
					'readOnly' => true
				]
			]],
			[true, false, false, false, [
				[
					'href' => 'principal:' . self::UNIT_TEST_USER1,
					'readOnly' => true
				],
			]],

		];
	}

	/**
	 * @dataProvider providesSharingData
	 * @throws NotFound
	 * @throws \Sabre\DAV\Exception
	 */
	public function testCalendarSharing($userCanRead, $userCanWrite, $groupCanRead, $groupCanWrite, $add) {
		/** @var IL10N | \PHPUnit\Framework\MockObject\MockObject $l10n */
		$l10n = $this->getMockBuilder(IL10N::class)
			->disableOriginalConstructor()->getMock();
		$l10n
			->expects($this->any())
			->method('t')
			->will($this->returnCallback(function ($text, $parameters = []) {
				return \vsprintf($text, $parameters);
			}));

		$calendarId = $this->createTestCalendar();
		$books = $this->backend->getCalendarsForUser(self::UNIT_TEST_USER);
		$this->assertCount(1, $books);
		$calendar = new Calendar($this->backend, $books[0], $l10n);
		$this->backend->updateShares($calendar, $add, []);
		$books = $this->backend->getCalendarsForUser(self::UNIT_TEST_USER1);
		$this->assertCount(1, $books);
		$calendar = new Calendar($this->backend, $books[0], $l10n);
		$acl = $calendar->getACL();
		$this->assertAcl(self::UNIT_TEST_USER, '{DAV:}read', $acl);
		$this->assertAcl(self::UNIT_TEST_USER, '{DAV:}write', $acl);
		$this->assertAccess($userCanRead, self::UNIT_TEST_USER1, '{DAV:}read', $acl);
		$this->assertAccess($userCanWrite, self::UNIT_TEST_USER1, '{DAV:}write', $acl);
		$this->assertAccess($groupCanRead, self::UNIT_TEST_GROUP, '{DAV:}read', $acl);
		$this->assertAccess($groupCanWrite, self::UNIT_TEST_GROUP, '{DAV:}write', $acl);
		$this->assertEquals(self::UNIT_TEST_USER, $calendar->getOwner());

		// test acls on the child
		$uri = static::getUniqueID('calobj');
		$calData = <<<'EOD'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:ownCloud Calendar
BEGIN:VEVENT
CREATED;VALUE=DATE-TIME:20130910T125139Z
UID:47d15e3ec8
LAST-MODIFIED;VALUE=DATE-TIME:20130910T125139Z
DTSTAMP;VALUE=DATE-TIME:20130910T125139Z
SUMMARY:Test Event
DTSTART;VALUE=DATE-TIME:20130912T130000Z
DTEND;VALUE=DATE-TIME:20130912T140000Z
CLASS:PUBLIC
END:VEVENT
END:VCALENDAR
EOD;

		$this->backend->createCalendarObject($calendarId, $uri, $calData);

		/** @var IACL $child */
		$child = $calendar->getChild($uri);
		$acl = $child->getACL();
		$this->assertAcl(self::UNIT_TEST_USER, '{DAV:}read', $acl);
		$this->assertAcl(self::UNIT_TEST_USER, '{DAV:}write', $acl);
		$this->assertAccess($userCanRead, self::UNIT_TEST_USER1, '{DAV:}read', $acl);
		$this->assertAccess($userCanWrite, self::UNIT_TEST_USER1, '{DAV:}write', $acl);
		$this->assertAccess($groupCanRead, self::UNIT_TEST_GROUP, '{DAV:}read', $acl);
		$this->assertAccess($groupCanWrite, self::UNIT_TEST_GROUP, '{DAV:}write', $acl);

		// delete the address book
		$this->backend->deleteCalendar($books[0]['id']);
		$books = $this->backend->getCalendarsForUser(self::UNIT_TEST_USER);
		$this->assertCount(0, $books);
	}

	public function providesCalendarData() {
		$initialCalendar = <<<'EOD'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:ownCloud Calendar
BEGIN:VEVENT
CREATED;VALUE=DATE-TIME:20130910T125139Z
UID:47d15e3ec8
LAST-MODIFIED;VALUE=DATE-TIME:20130910T125139Z
DTSTAMP;VALUE=DATE-TIME:20130910T125139Z
SUMMARY:Test Event
DTSTART;VALUE=DATE-TIME:20130912T130000Z
DTEND;VALUE=DATE-TIME:20130912T140000Z
CLASS:PUBLIC
END:VEVENT
END:VCALENDAR
EOD;

		$updatedCalendar = <<<'EOD'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:ownCloud Calendar
BEGIN:VEVENT
CREATED;VALUE=DATE-TIME:20130910T125139Z
UID:47d15e3ec8
LAST-MODIFIED;VALUE=DATE-TIME:20130910T125139Z
DTSTAMP;VALUE=DATE-TIME:20130910T125139Z
SUMMARY:Test Event
DTSTART;VALUE=DATE-TIME:20130912T130000Z
DTEND;VALUE=DATE-TIME:20130912T140000Z
END:VEVENT
END:VCALENDAR
EOD;

		$initialCalendar2 = <<<'EOD'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Sabre//Sabre VObject 3.5.0//EN
CALSCALE:GREGORIAN
METHOD:REQUEST
BEGIN:VTIMEZONE
TZID:Europe/Warsaw
BEGIN:DAYLIGHT
TZOFFSETFROM:+0100
TZOFFSETTO:+0200
TZNAME:CEST
DTSTART:19700329T020000
RRULE:FREQ=YEARLY;BYDAY=-1SU;BYMONTH=3
END:DAYLIGHT
BEGIN:STANDARD
TZOFFSETFROM:+0200
TZOFFSETTO:+0100
TZNAME:CET
DTSTART:19701025T030000
RRULE:FREQ=YEARLY;BYDAY=-1SU;BYMONTH=10
END:STANDARD
END:VTIMEZONE
BEGIN:VEVENT
CREATED:20170320T131655Z
LAST-MODIFIED:20170320T135019Z
DTSTAMP:20170320T135019Z
UID:7e908a6d-4c4e-48d7-bd62-59ab80fbf1a3
SUMMARY:TEST Z pg_escape_bytea
ORGANIZER;RSVP=TRUE;PARTSTAT=ACCEPTED;ROLE=CHAIR:mailto:k.klimczak@gromar.e
 u
ATTENDEE;RSVP=TRUE;CN=Zuzanna Leszek;PARTSTAT=NEEDS-ACTION;ROLE=REQ-PARTICI
 PANT:mailto:z.leszek@gromar.eu
ATTENDEE;RSVP=TRUE;CN=Marcin Pisarski;PARTSTAT=NEEDS-ACTION;ROLE=REQ-PARTIC
 IPANT:mailto:m.pisarski@gromar.eu
ATTENDEE;RSVP=TRUE;PARTSTAT=NEEDS-ACTION;ROLE=REQ-PARTICIPANT:mailto:klimcz
 ak.k@gmail.com
ATTENDEE;RSVP=TRUE;PARTSTAT=NEEDS-ACTION;ROLE=REQ-PARTICIPANT:mailto:k_klim
 czak@tlen.pl
DTSTART;TZID=Europe/Warsaw:20170325T150000
DTEND;TZID=Europe/Warsaw:20170325T160000
TRANSP:OPAQUE
DESCRIPTION:Magiczna treść uzyskana za pomocą magicznego proszku.\n\nę
 żźćńłóÓŻŹĆŁĘ€śśśŚŚ\n               \,\,))))))))\;\,\n  
           __))))))))))))))\,\n \\|/       -\\(((((''''((((((((.\n -*-==///
 ///((''  .     `))))))\,\n /|\\      ))| o    \;-.    '(((((              
                     \,(\,\n          ( `|    /  )    \;))))'              
                  \,_))^\;(~\n             |   |   |   \,))((((_     _____-
 -----~~~-.        %\,\;(\;(>'\;'~\n             o_)\;   \;    )))(((` ~---
 ~  `::           \\      %%~~)(v\;(`('~\n                   \;    ''''````
          `:       `:::|\\\,__\,%%    )\;`'\; ~\n                  |   _   
              )     /      `:|`----'     `-'\n            ______/\\/~    | 
                 /        /\n          /~\;\;.____/\;\;'  /          ___--\
 ,-(   `\;\;\;/\n         / //  _\;______\;'------~~~~~    /\;\;/\\    /\n 
        //  | |                        / \;   \\\;\;\,\\\n       (<_  | \; 
                      /'\,/-----'  _>\n        \\_| ||_                    
  //~\;~~~~~~~~~\n            `\\_|                   (\,~~  -Tua Xiong\n  
                                   \\~\\\n                                 
     ~~\n\n
SEQUENCE:1
X-MOZ-GENERATION:1
END:VEVENT
END:VCALENDAR
EOD;

		return[
			[$initialCalendar, $updatedCalendar],
			[$initialCalendar2, $initialCalendar2],
		];
	}

	/**
	 * @dataProvider providesCalendarData
	 * @param $initialCalendar
	 * @param $updatedCalendar
	 * @throws \Sabre\DAV\Exception
	 * @throws \Sabre\DAV\Exception\BadRequest
	 */
	public function testCalendarObjectsOperations($initialCalendar, $updatedCalendar) {
		$calendarId = $this->createTestCalendar();

		// create a card
		$uri = static::getUniqueID('calobj');
		$this->backend->createCalendarObject($calendarId, $uri, $initialCalendar);

		// get all the cards
		$calendarObjects = $this->backend->getCalendarObjects($calendarId);
		$this->assertCount(1, $calendarObjects);
		$this->assertEquals($calendarId, $calendarObjects[0]['calendarid']);
		$this->assertArrayHasKey('classification', $calendarObjects[0]);

		// get the cards
		$calendarObject = $this->backend->getCalendarObject($calendarId, $uri);
		$this->assertNotNull($calendarObject);
		$this->assertArrayHasKey('id', $calendarObject);
		$this->assertArrayHasKey('uri', $calendarObject);
		$this->assertArrayHasKey('lastmodified', $calendarObject);
		$this->assertArrayHasKey('etag', $calendarObject);
		$this->assertArrayHasKey('size', $calendarObject);
		$this->assertArrayHasKey('classification', $calendarObject);
		$this->assertEquals($initialCalendar, $calendarObject['calendardata']);

		// update the card
		$this->backend->updateCalendarObject($calendarId, $uri, $updatedCalendar);
		$calendarObject = $this->backend->getCalendarObject($calendarId, $uri);
		$this->assertEquals($updatedCalendar, $calendarObject['calendardata']);

		// delete the card
		$this->backend->deleteCalendarObject($calendarId, $uri);
		$calendarObjects = $this->backend->getCalendarObjects($calendarId);
		$this->assertCount(0, $calendarObjects);
	}

	/**
	 * @throws \Sabre\DAV\Exception
	 */
	public function testMultiCalendarObjects() {
		$calendarId = $this->createTestCalendar();

		// create an event
		$calData = <<<'EOD'
BEGIN:VCALENDAR
VERSION:2.0
PRODID:ownCloud Calendar
BEGIN:VEVENT
CREATED;VALUE=DATE-TIME:20130910T125139Z
UID:47d15e3ec8
LAST-MODIFIED;VALUE=DATE-TIME:20130910T125139Z
DTSTAMP;VALUE=DATE-TIME:20130910T125139Z
SUMMARY:Test Event
DTSTART;VALUE=DATE-TIME:20130912T130000Z
DTEND;VALUE=DATE-TIME:20130912T140000Z
CLASS:PUBLIC
END:VEVENT
END:VCALENDAR
EOD;
		$uri0 = static::getUniqueID('card');
		$this->backend->createCalendarObject($calendarId, $uri0, $calData);
		$uri1 = static::getUniqueID('card');
		$this->backend->createCalendarObject($calendarId, $uri1, $calData);
		$uri2 = static::getUniqueID('card');
		$this->backend->createCalendarObject($calendarId, $uri2, $calData);

		// get all the cards
		$calendarObjects = $this->backend->getCalendarObjects($calendarId);
		$this->assertCount(3, $calendarObjects);

		// get the cards
		$calendarObjects = $this->backend->getMultipleCalendarObjects($calendarId, [$uri1, $uri2]);
		$this->assertCount(2, $calendarObjects);
		foreach ($calendarObjects as $card) {
			$this->assertArrayHasKey('id', $card);
			$this->assertArrayHasKey('uri', $card);
			$this->assertArrayHasKey('lastmodified', $card);
			$this->assertArrayHasKey('etag', $card);
			$this->assertArrayHasKey('size', $card);
			$this->assertArrayHasKey('classification', $card);
			$this->assertEquals($calData, $card['calendardata']);
		}

		// delete the card
		$this->backend->deleteCalendarObject($calendarId, $uri0);
		$this->backend->deleteCalendarObject($calendarId, $uri1);
		$this->backend->deleteCalendarObject($calendarId, $uri2);
		$calendarObjects = $this->backend->getCalendarObjects($calendarId);
		$this->assertCount(0, $calendarObjects);
	}

	/**
	 * @dataProvider providesCalendarQueryParameters
	 * @throws \Sabre\DAV\Exception
	 */
	public function testCalendarQuery($expectedEventsInResult, $propFilters, $compFilter) {
		$calendarId = $this->createTestCalendar();
		$events = [];
		$events[0] = $this->createEvent($calendarId, '20130912T130000Z', '20130912T140000Z');
		$events[1] = $this->createEvent($calendarId, '20130912T150000Z', '20130912T170000Z');
		$events[2] = $this->createEvent($calendarId, '20130912T173000Z', '20130912T220000Z');
		$events[3] = $this->createEvent($calendarId, '21130912T130000Z', '22130912T130000Z');

		$result = $this->backend->calendarQuery($calendarId, [
			'name' => '',
			'prop-filters' => $propFilters,
			'comp-filters' => $compFilter
		]);

		$expectedEventsInResult = \array_map(function ($index) use ($events) {
			return $events[$index];
		}, $expectedEventsInResult);
		$this->assertEqualsCanonicalizing($expectedEventsInResult, $result);
	}

	/**
	 * @throws \Sabre\DAV\Exception
	 */
	public function testGetCalendarObjectByUID() {
		$calendarId = $this->createTestCalendar();
		$this->createEvent($calendarId, '20130912T130000Z', '20130912T140000Z');

		$co = $this->backend->getCalendarObjectByUID(self::UNIT_TEST_USER, '47d15e3ec8');
		$this->assertNotNull($co);
	}

	public function providesCalendarQueryParameters() {
		return [
			'all' => [[0, 1, 2, 3], [], []],
			'only-todos' => [[], ['name' => 'VTODO'], []],
			'only-events' => [[0, 1, 2, 3], [], [['name' => 'VEVENT', 'is-not-defined' => false, 'comp-filters' => [], 'time-range' => ['start' => null, 'end' => null], 'prop-filters' => []]],],
			'time-range false' => [[0, 1, 2, 3], [], [['name' => 'VEVENT', 'is-not-defined' => false, 'comp-filters' => [], 'time-range' => false, 'prop-filters' => []]],],
			'start' => [[1, 2, 3], [], [['name' => 'VEVENT', 'is-not-defined' => false, 'comp-filters' => [], 'time-range' => ['start' => new DateTime('2013-09-12 14:00:00', new DateTimeZone('UTC')), 'end' => null], 'prop-filters' => []]],],
			'end' => [[0], [], [['name' => 'VEVENT', 'is-not-defined' => false, 'comp-filters' => [], 'time-range' => ['start' => null, 'end' => new DateTime('2013-09-12 14:00:00', new DateTimeZone('UTC'))], 'prop-filters' => []]],],
			'future' => [[3], [], [['name' => 'VEVENT', 'is-not-defined' => false, 'comp-filters' => [], 'time-range' => ['start' => new DateTime('2099-09-12 14:00:00', new DateTimeZone('UTC')), 'end' => null], 'prop-filters' => []]],],
		];
	}

	/**
	 * @throws \Sabre\DAV\Exception
	 */
	public function testSyncSupport() {
		$calendarId = $this->createTestCalendar();

		// fist call without synctoken
		$changes = $this->backend->getChangesForCalendar($calendarId, '', 1, 1000);
		$syncToken = $changes['syncToken'];

		// add a change
		$event = $this->createEvent($calendarId, '20130912T130000Z', '20130912T140000Z');

		// look for changes
		$changes = $this->backend->getChangesForCalendar($calendarId, $syncToken, 1, 1000);
		$this->assertEquals($event, $changes['added'][0]);
	}

	/**
	 * @throws \Sabre\DAV\Exception
	 * @throws \Sabre\DAV\Exception\NotFound
	 */
	public function testPublications() {
		$this->backend->createCalendar(self::UNIT_TEST_USER, 'Example', []);

		$calendarInfo = $this->backend->getCalendarsForUser(self::UNIT_TEST_USER)[0];

		/** @var IL10N $l10n */
		$l10n = $this->getMockBuilder(IL10N::class)
			->disableOriginalConstructor()->getMock();

		$calendar = new Calendar($this->backend, $calendarInfo, $l10n);
		$calendar->setPublishStatus(true);
		$this->assertNotFalse($calendar->getPublishStatus());

		$publicCalendars = $this->backend->getPublicCalendars();
		$this->assertCount(1, $publicCalendars);
		$this->assertTrue($publicCalendars[0]['{http://owncloud.org/ns}public']);

		$publicCalendarURI = $publicCalendars[0]['uri'];
		$publicCalendar = $this->backend->getPublicCalendar($publicCalendarURI);
		$this->assertTrue($publicCalendar['{http://owncloud.org/ns}public']);

		$calendar->setPublishStatus(false);
		$this->assertFalse($calendar->getPublishStatus());

		$publicCalendarURI = \md5($this->config->getSystemValue('secret', '') . $calendar->getResourceId());
		$this->expectException(NotFound::class);
		$this->backend->getPublicCalendar($publicCalendarURI);
	}

	/**
	 * @throws \Sabre\DAV\Exception\Forbidden
	 */
	public function testSubscriptions() {
		$id = $this->backend->createSubscription(self::UNIT_TEST_USER, 'Subscription', [
			'{http://calendarserver.org/ns/}source' => new Href('test-source'),
			'{http://apple.com/ns/ical/}calendar-color' => '#1C4587',
			'{http://calendarserver.org/ns/}subscribed-strip-todos' => ''
		]);

		$subscriptions = $this->backend->getSubscriptionsForUser(self::UNIT_TEST_USER);
		$this->assertCount(1, $subscriptions);
		$this->assertEquals('#1C4587', $subscriptions[0]['{http://apple.com/ns/ical/}calendar-color']);
		$this->assertEquals(true, $subscriptions[0]['{http://calendarserver.org/ns/}subscribed-strip-todos']);
		$this->assertEquals($id, $subscriptions[0]['id']);

		$patch = new PropPatch([
				'{DAV:}displayname' => 'Unit test',
				'{http://apple.com/ns/ical/}calendar-color' => '#ac0606',
		]);
		$this->backend->updateSubscription($id, $patch);
		$patch->commit();

		$subscriptions = $this->backend->getSubscriptionsForUser(self::UNIT_TEST_USER);
		$this->assertCount(1, $subscriptions);
		$this->assertEquals($id, $subscriptions[0]['id']);
		$this->assertEquals('Unit test', $subscriptions[0]['{DAV:}displayname']);
		$this->assertEquals('#ac0606', $subscriptions[0]['{http://apple.com/ns/ical/}calendar-color']);

		$this->backend->deleteSubscription($id);
		$subscriptions = $this->backend->getSubscriptionsForUser(self::UNIT_TEST_USER);
		$this->assertCount(0, $subscriptions);
	}

	public function providesSchedulingData() {
		$data =<<<EOS
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Sabre//Sabre VObject 3.5.0//EN
CALSCALE:GREGORIAN
METHOD:REQUEST
BEGIN:VTIMEZONE
TZID:Europe/Warsaw
BEGIN:DAYLIGHT
TZOFFSETFROM:+0100
TZOFFSETTO:+0200
TZNAME:CEST
DTSTART:19700329T020000
RRULE:FREQ=YEARLY;BYDAY=-1SU;BYMONTH=3
END:DAYLIGHT
BEGIN:STANDARD
TZOFFSETFROM:+0200
TZOFFSETTO:+0100
TZNAME:CET
DTSTART:19701025T030000
RRULE:FREQ=YEARLY;BYDAY=-1SU;BYMONTH=10
END:STANDARD
END:VTIMEZONE
BEGIN:VEVENT
CREATED:20170320T131655Z
LAST-MODIFIED:20170320T135019Z
DTSTAMP:20170320T135019Z
UID:7e908a6d-4c4e-48d7-bd62-59ab80fbf1a3
SUMMARY:TEST Z pg_escape_bytea
ORGANIZER;RSVP=TRUE;PARTSTAT=ACCEPTED;ROLE=CHAIR:mailto:k.klimczak@gromar.e
 u
ATTENDEE;RSVP=TRUE;CN=Zuzanna Leszek;PARTSTAT=NEEDS-ACTION;ROLE=REQ-PARTICI
 PANT:mailto:z.leszek@gromar.eu
ATTENDEE;RSVP=TRUE;CN=Marcin Pisarski;PARTSTAT=NEEDS-ACTION;ROLE=REQ-PARTIC
 IPANT:mailto:m.pisarski@gromar.eu
ATTENDEE;RSVP=TRUE;PARTSTAT=NEEDS-ACTION;ROLE=REQ-PARTICIPANT:mailto:klimcz
 ak.k@gmail.com
ATTENDEE;RSVP=TRUE;PARTSTAT=NEEDS-ACTION;ROLE=REQ-PARTICIPANT:mailto:k_klim
 czak@tlen.pl
DTSTART;TZID=Europe/Warsaw:20170325T150000
DTEND;TZID=Europe/Warsaw:20170325T160000
TRANSP:OPAQUE
DESCRIPTION:Magiczna treść uzyskana za pomocą magicznego proszku.\n\nę
 żźćńłóÓŻŹĆŁĘ€śśśŚŚ\n               \,\,))))))))\;\,\n  
           __))))))))))))))\,\n \\|/       -\\(((((''''((((((((.\n -*-==///
 ///((''  .     `))))))\,\n /|\\      ))| o    \;-.    '(((((              
                     \,(\,\n          ( `|    /  )    \;))))'              
                  \,_))^\;(~\n             |   |   |   \,))((((_     _____-
 -----~~~-.        %\,\;(\;(>'\;'~\n             o_)\;   \;    )))(((` ~---
 ~  `::           \\      %%~~)(v\;(`('~\n                   \;    ''''````
          `:       `:::|\\\,__\,%%    )\;`'\; ~\n                  |   _   
              )     /      `:|`----'     `-'\n            ______/\\/~    | 
                 /        /\n          /~\;\;.____/\;\;'  /          ___--\
 ,-(   `\;\;\;/\n         / //  _\;______\;'------~~~~~    /\;\;/\\    /\n 
        //  | |                        / \;   \\\;\;\,\\\n       (<_  | \; 
                      /'\,/-----'  _>\n        \\_| ||_                    
  //~\;~~~~~~~~~\n            `\\_|                   (\,~~  -Tua Xiong\n  
                                   \\~\\\n                                 
     ~~\n\n
SEQUENCE:1
X-MOZ-GENERATION:1
END:VEVENT
END:VCALENDAR
EOS;

		return [
			'no data' => [''],
			'failing on postgres' => [$data]
		];
	}

	/**
	 * @dataProvider providesSchedulingData
	 * @param $objectData
	 */
	public function testScheduling($objectData) {
		$this->backend->createSchedulingObject(self::UNIT_TEST_USER, 'Sample Schedule', $objectData);

		$sos = $this->backend->getSchedulingObjects(self::UNIT_TEST_USER);
		$this->assertCount(1, $sos);

		$so = $this->backend->getSchedulingObject(self::UNIT_TEST_USER, 'Sample Schedule');
		$this->assertNotNull($so);

		$this->backend->deleteSchedulingObject(self::UNIT_TEST_USER, 'Sample Schedule');

		$sos = $this->backend->getSchedulingObjects(self::UNIT_TEST_USER);
		$this->assertCount(0, $sos);
	}

	/**
	 * @dataProvider providesCalDataForGetDenormalizedData
	 * @throws \Sabre\DAV\Exception\BadRequest
	 */
	public function testGetDenormalizedData($expected, $key, $calData) {
		$actual = $this->backend->getDenormalizedData($calData);
		$this->assertEquals($expected, $actual[$key]);
	}

	public function providesCalDataForGetDenormalizedData() {
		return [
			'first occurrence before unix epoch starts' => [0, 'firstOccurence', "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Sabre//Sabre VObject 4.5.7//EN\r\nCALSCALE:GREGORIAN\r\nBEGIN:VEVENT\r\nUID:413F269B-B51B-46B1-AFB6-40055C53A4DC\r\nDTSTAMP:20160309T095056Z\r\nDTSTART;VALUE=DATE:16040222\r\nDTEND;VALUE=DATE:16040223\r\nRRULE:FREQ=YEARLY\r\nSUMMARY:SUMMARY\r\nTRANSP:TRANSPARENT\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"],
			'no first occurrence because yearly' => [null, 'firstOccurence', "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Sabre//Sabre VObject 4.5.7//EN\r\nCALSCALE:GREGORIAN\r\nBEGIN:VEVENT\r\nUID:413F269B-B51B-46B1-AFB6-40055C53A4DC\r\nDTSTAMP:20160309T095056Z\r\nRRULE:FREQ=YEARLY\r\nSUMMARY:SUMMARY\r\nTRANSP:TRANSPARENT\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n"],
			'CLASS:PRIVATE' => [CalDavBackend::CLASSIFICATION_PRIVATE, 'classification', "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//dmfs.org//mimedir.icalendar//EN\r\nBEGIN:VTIMEZONE\r\nTZID:Europe/Berlin\r\nX-LIC-LOCATION:Europe/Berlin\r\nBEGIN:DAYLIGHT\r\nTZOFFSETFROM:+0100\r\nTZOFFSETTO:+0200\r\nTZNAME:CEST\r\nDTSTART:19700329T020000\r\nRRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU\r\nEND:DAYLIGHT\r\nBEGIN:STANDARD\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\nTZNAME:CET\r\nDTSTART:19701025T030000\r\nRRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\nBEGIN:VEVENT\r\nDTSTART;TZID=Europe/Berlin:20160419T130000\r\nSUMMARY:Test\r\nCLASS:PRIVATE\r\nTRANSP:OPAQUE\r\nSTATUS:CONFIRMED\r\nDTEND;TZID=Europe/Berlin:20160419T140000\r\nLAST-MODIFIED:20160419T074202Z\r\nDTSTAMP:20160419T074202Z\r\nCREATED:20160419T074202Z\r\nUID:2e468c48-7860-492e-bc52-92fa0daeeccf.1461051722310\r\nEND:VEVENT\r\nEND:VCALENDAR"],
			'CLASS:PUBLIC' => [CalDavBackend::CLASSIFICATION_PUBLIC, 'classification', "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//dmfs.org//mimedir.icalendar//EN\r\nBEGIN:VTIMEZONE\r\nTZID:Europe/Berlin\r\nX-LIC-LOCATION:Europe/Berlin\r\nBEGIN:DAYLIGHT\r\nTZOFFSETFROM:+0100\r\nTZOFFSETTO:+0200\r\nTZNAME:CEST\r\nDTSTART:19700329T020000\r\nRRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU\r\nEND:DAYLIGHT\r\nBEGIN:STANDARD\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\nTZNAME:CET\r\nDTSTART:19701025T030000\r\nRRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\nBEGIN:VEVENT\r\nDTSTART;TZID=Europe/Berlin:20160419T130000\r\nSUMMARY:Test\r\nCLASS:PUBLIC\r\nTRANSP:OPAQUE\r\nSTATUS:CONFIRMED\r\nDTEND;TZID=Europe/Berlin:20160419T140000\r\nLAST-MODIFIED:20160419T074202Z\r\nDTSTAMP:20160419T074202Z\r\nCREATED:20160419T074202Z\r\nUID:2e468c48-7860-492e-bc52-92fa0daeeccf.1461051722310\r\nEND:VEVENT\r\nEND:VCALENDAR"],
			'CLASS:CONFIDENTIAL' => [CalDavBackend::CLASSIFICATION_CONFIDENTIAL, 'classification', "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//dmfs.org//mimedir.icalendar//EN\r\nBEGIN:VTIMEZONE\r\nTZID:Europe/Berlin\r\nX-LIC-LOCATION:Europe/Berlin\r\nBEGIN:DAYLIGHT\r\nTZOFFSETFROM:+0100\r\nTZOFFSETTO:+0200\r\nTZNAME:CEST\r\nDTSTART:19700329T020000\r\nRRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU\r\nEND:DAYLIGHT\r\nBEGIN:STANDARD\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\nTZNAME:CET\r\nDTSTART:19701025T030000\r\nRRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\nBEGIN:VEVENT\r\nDTSTART;TZID=Europe/Berlin:20160419T130000\r\nSUMMARY:Test\r\nCLASS:CONFIDENTIAL\r\nTRANSP:OPAQUE\r\nSTATUS:CONFIRMED\r\nDTEND;TZID=Europe/Berlin:20160419T140000\r\nLAST-MODIFIED:20160419T074202Z\r\nDTSTAMP:20160419T074202Z\r\nCREATED:20160419T074202Z\r\nUID:2e468c48-7860-492e-bc52-92fa0daeeccf.1461051722310\r\nEND:VEVENT\r\nEND:VCALENDAR"],
			'no class set -> public' => [CalDavBackend::CLASSIFICATION_PUBLIC, 'classification', "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//dmfs.org//mimedir.icalendar//EN\r\nBEGIN:VTIMEZONE\r\nTZID:Europe/Berlin\r\nX-LIC-LOCATION:Europe/Berlin\r\nBEGIN:DAYLIGHT\r\nTZOFFSETFROM:+0100\r\nTZOFFSETTO:+0200\r\nTZNAME:CEST\r\nDTSTART:19700329T020000\r\nRRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU\r\nEND:DAYLIGHT\r\nBEGIN:STANDARD\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\nTZNAME:CET\r\nDTSTART:19701025T030000\r\nRRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\nBEGIN:VEVENT\r\nDTSTART;TZID=Europe/Berlin:20160419T130000\r\nSUMMARY:Test\r\nTRANSP:OPAQUE\r\nDTEND;TZID=Europe/Berlin:20160419T140000\r\nLAST-MODIFIED:20160419T074202Z\r\nDTSTAMP:20160419T074202Z\r\nCREATED:20160419T074202Z\r\nUID:2e468c48-7860-492e-bc52-92fa0daeeccf.1461051722310\r\nEND:VEVENT\r\nEND:VCALENDAR"],
			'unknown class -> private' => [CalDavBackend::CLASSIFICATION_PRIVATE, 'classification', "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//dmfs.org//mimedir.icalendar//EN\r\nBEGIN:VTIMEZONE\r\nTZID:Europe/Berlin\r\nX-LIC-LOCATION:Europe/Berlin\r\nBEGIN:DAYLIGHT\r\nTZOFFSETFROM:+0100\r\nTZOFFSETTO:+0200\r\nTZNAME:CEST\r\nDTSTART:19700329T020000\r\nRRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU\r\nEND:DAYLIGHT\r\nBEGIN:STANDARD\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\nTZNAME:CET\r\nDTSTART:19701025T030000\r\nRRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\nBEGIN:VEVENT\r\nDTSTART;TZID=Europe/Berlin:20160419T130000\r\nSUMMARY:Test\r\nCLASS:VERTRAULICH\r\nTRANSP:OPAQUE\r\nSTATUS:CONFIRMED\r\nDTEND;TZID=Europe/Berlin:20160419T140000\r\nLAST-MODIFIED:20160419T074202Z\r\nDTSTAMP:20160419T074202Z\r\nCREATED:20160419T074202Z\r\nUID:2e468c48-7860-492e-bc52-92fa0daeeccf.1461051722310\r\nEND:VEVENT\r\nEND:VCALENDAR"],
		];
	}

	/**
	 * @throws \Sabre\DAV\Exception
	 */
	public function testHugeMultiGet() {
		$calendarId = $this->createTestCalendar();
		$urls = \array_map(function ($number) {
			return "url-$number";
		}, \range(0, 2000));
		$calendarObjects = $this->backend->getMultipleCalendarObjects($calendarId, $urls);
		$this->assertEquals([], $calendarObjects);
	}
}
