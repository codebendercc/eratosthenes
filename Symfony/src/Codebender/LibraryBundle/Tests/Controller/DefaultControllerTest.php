<?php

namespace Codebender\LibraryBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class DefaultControllerTest extends WebTestCase
{
	public function testStatus()
	{
		$client = static::createClient();

		$client->request('GET', '/status');

		$this->assertEquals($client->getResponse()->getContent(), '{"success":true,"status":"OK"}');

	}

	public function testInvalidKey()
	{
		$client = static::createClient();

		$client->request('GET', '/inValidKey/v1');

		$this->assertEquals($client->getResponse()->getContent(), '{"success":false,"step":0,"message":"Invalid authorization key."}');

	}

	public function testInvalidAPI()
	{
		$client = static::createClient();

		$auth_key = $client->getContainer()->getParameter("auth_key");

		$client->request('GET', '/'.$auth_key.'/v666');

		$this->assertEquals($client->getResponse()->getContent(), '{"success":false,"step":0,"message":"Invalid API version."}');

	}

	public function testIncorrectInputs()
	{
		$this->markTestIncomplete("No tests for invalid inputs yet");
	}

	public function testList()
	{
		$client = static::createClient();

		$auth_key = $client->getContainer()->getParameter("auth_key");

		$client->request('GET', '/'.$auth_key.'/v1');

		$this->assertTrue(false, "There are no tests yet.");
	}

	public function testGetFile()
	{
		$this->assertTrue(false, "There are no tests yet.");
	}
}
