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
        
        $this->assertResponseIsSuccessful('/ response');
        $this->assertSelectorTextContains('h1', 'Obtention de consentement pour DomoticzLinky', '/ text');
    }

    public function testDevice(): void
    {
        $client = static::createClient();
        $code = 'XRWV-EPJG';
        
        $client->request('GET', '/device?code='.$code);
        $this->assertResponseIsSuccessful('/device response');
        $this->assertInputValueSame('code', $code, '/device code');
    }

    public function testVerify(): void
    {
        $client = static::createClient();
        
        $client->request('GET', '/auth/verify_code');
        $this->assertResponseIsSuccessful('/auth/verify_code response');
        $this->assertSelectorTextContains('p', 'Aucun code n\'a été entré', '/auth/verify_code code missing');
        
        $code = 'XRWV-EPJG';
        $client->request('GET', '/auth/verify_code', ['code' => $code]);
        $this->assertSelectorTextContains('p', 'Code non valide', '/auth/verify_code invalid code');
        
        $code = 'ADXFD-DSFS';
        $client->request('POST', '/device/code');
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST, '/device/code client_id missing');
        
        $client->request('POST', '/device/code', [ 'client_id' => 'test' ]);
        $this->assertResponseIsSuccessful('/device/code response');
        $jsonResponse = json_decode($client->getResponse()->getContent(), true);
        
        //dump($client);
        //dump($jsonResponse);
        //dump('test');
        
        $client->request('GET', '/auth/verify_code', ['code' => $jsonResponse['user_code'] ]);
        //dump($client);
        //dump(get_class_methods($client));
        $this->assertResponseStatusCodeSame(Response::HTTP_FOUND, '/auth/verify_code redirect');
        $location = $client->getResponse()->headers->get('location');
        //dump($location);
        $query = parse_url($location, PHP_URL_QUERY);
        parse_str($query, $redirect_params);
        //dump($redirect_params);
        $this->assertArrayHasKey('state', $redirect_params, '/auth/verify_code returns state');
        
        $client->request('GET', '/auth/redirect', ['error' => 'testerror1', 'error_description' => 'testerror2']);
        //dump($client->getResponse()->getContent());
        $this->assertResponseIsSuccessful('/auth/redirect response');
        $this->assertAnySelectorTextContains('h2', 'testerror1', '/auth/redirect error missing');
        $this->assertSelectorTextContains('p', 'testerror2', '/auth/redirect error_description missing');

        $client->request('GET', '/auth/redirect');
        $this->assertSelectorTextContains('p', 'Des paramètres manquent dans la requête', '/auth/redirect state missing');
        
        $client->request('GET', '/auth/redirect', ['code' => 'abcd', 'state' => 'abcd']);
        $this->assertSelectorTextContains('p', 'Le paramètre state n\'est pas valide', '/auth/redirect invalid state');
        
        $client->request('GET', '/auth/redirect', ['code' => $jsonResponse['user_code'], 'state' => $redirect_params['state']]);
        //dump($client->getResponse()->getContent());
        $this->assertSelectorTextContains('p', 'Le paramètre usage_point_id manque dans la requête', '/auth/redirect usage_point_id missing');
        
        $client->request('GET', '/auth/redirect', ['code' => $jsonResponse['user_code'], 'state' => $redirect_params['state'], 'usage_point_id' => '123456789']);
        //dump($client->getResponse()->getContent());
        $this->assertAnySelectorTextContains('h2', 'Le consentement a bien été obtenu pour le plugin DomoticzLinky ! Vous pouvez fermer cette page et retourner sur Domoticz.', '/auth/redirect signed in');
    }
}
?>
