<?php
namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

class GlobalTest extends WebTestCase
{
    public function testMain(): void
    {
        $client = static::createClient();
	$client->request('GET', '/');
	$this->assertResponseIsSuccessful();
	$this->assertSelectorTextContains('h1', 'Obtention de consentement pour DomoticzLinky');
    }

    public function testDevice(): void
    {
        $client = static::createClient();
	$code = "XRWV-EPJG";
        $client->request('GET', '/device?code='.$code);
        $this->assertResponseIsSuccessful();
        $this->assertInputValueSame('code', $code);
    }

    public function testVerify(): void
    {
        $client = static::createClient();
	$client->request('GET', '/auth/verify_code');
	$this->assertResponseIsSuccessful();
	$this->assertSelectorTextContains('p', 'Aucun code n\'a été entré');
	$code = "XRWV-EPJG";
        $client->request('GET', '/auth/verify_code', ['code' => $code]);
        $this->assertSelectorTextContains('p', 'Code non valide');
        $code = "ADXFD-DSFS";
        $client->request('POST', '/device/code');
	$this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
        $client->request('POST', '/device/code', [ 'client_id' => 'test' ]);
	$this->assertResponseIsSuccessful();
	$jsonResponse = json_decode($client->getResponse()->getContent(), true);
	//dump($client);
	//dump($jsonResponse);
	//dump("test");
	$client->request('GET', '/auth/verify_code', ['code' => $jsonResponse['user_code'] ]);
	//dump($client);
        $this->assertResponseStatusCodeSame(Response::HTTP_FOUND);
    }
}
?>
