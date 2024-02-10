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
        $client_id = $client->getKernel()->getContainer()->getParameter('app_client_id');
        $usage_point_id = '42900589957123';
        $_ENV['VERSION_MIN'] = '';
        $_ENV['DATA_ENDPOINT'] = 'https://ext.hml.api.enedis.fr';
        
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
        $jsonResponseCode = json_decode($client->getResponse()->getContent(), true);
        
        //dump($client);
        //dump($jsonResponseCode);
        //dump('test');
        
        $client->request('GET', '/auth/verify_code', ['code' => $jsonResponseCode['user_code'] ]);
        //dump($client);
        //dump(get_class_methods($client));
        $this->assertResponseStatusCodeSame(Response::HTTP_FOUND, '/auth/verify_code redirect');
        $location = $client->getResponse()->headers->get('location');
        $url_parts = parse_url($location);
        $url = $url_parts['scheme'] . '://' . $url_parts['host'] . (isset($url_parts['path'])?$url_parts['path']:'');
        $target = $client->getKernel()->getContainer()->getParameter('app_authorization_endpoint');
        //dump($url);
        //dump($target);
        $this->assertEquals($url, $target, '/auth/verify_code returns location');
        $query = $url_parts['query'];
        parse_str($query, $redirect_params);
        //dump($redirect_params);
        $this->assertArrayHasKey('state', $redirect_params, '/auth/verify_code returns state');

        $_ENV['VERSION_MIN'] = '99.99.99';
        
        $client->request('POST', '/device/token');
        //dump($client->getResponse()->getContent());
        $jsonResponseToken = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $jsonResponseToken, '/device/token version check error present');
        $this->assertEquals($jsonResponseToken['error'], 'version_mismatch', '/device/token version check error message');

        $_ENV['VERSION_MIN'] = '';

        $client->request('POST', '/device/token');
        //dump($client->getResponse()->getContent());
        $jsonResponseToken = json_decode($client->getResponse()->getContent(), true);
        $this->assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST, '/device/token error code');
        $this->assertEquals($jsonResponseToken['error_description'], 'Missing client_id or grant_type', '/device/token client_id or grant_type missing');
        
        $client->request('POST', '/device/token', ['client_id' => 'test']);
        $jsonResponseToken = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals($jsonResponseToken['error_description'], 'Missing client_id or grant_type', '/device/token client_id missing');
        
        $client->request('POST', '/device/token', ['client_id' => 'test', 'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code']);
        $jsonResponseToken = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals($jsonResponseToken['error_description'], 'Missing device_code', '/device/token device_code missing');
        
        $client->request('POST', '/device/token', ['client_id' => 'test', 'grant_type' => 'test']);
        $jsonResponseToken = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals($jsonResponseToken['error'], 'unsupported_grant_type', '/device/token unsupported_grant_type');
        
        $client->request('POST', '/device/token', ['client_id' => 'test', 'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code', 'device_code' => '1234']);
        $jsonResponseToken = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals($jsonResponseToken['error_description'], 'device_code not found in db', '/device/token device_code not found in db');
        
        $client->request('POST', '/device/token', ['client_id' => 'test', 'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code', 'device_code' => $jsonResponseCode['device_code']]);
        //dump($client->getResponse()->getContent());
        $jsonResponseToken = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('error', $jsonResponseToken, '/device/token error in array');
        $this->assertEquals($jsonResponseToken['error'], 'authorization_pending', '/device/token authorization_pending');

        $client->request('GET', '/auth/redirect', ['error' => 'testerror1', 'error_description' => 'testerror2']);
        //dump($client->getResponse()->getContent());
        $this->assertResponseIsSuccessful('/auth/redirect response');
        $this->assertAnySelectorTextContains('h2', 'testerror1', '/auth/redirect error missing');
        $this->assertSelectorTextContains('p', 'testerror2', '/auth/redirect error_description missing');

        $client->request('GET', '/auth/redirect');
        $this->assertSelectorTextContains('p', 'Des paramètres manquent dans la requête', '/auth/redirect state missing');
        
        $client->request('GET', '/auth/redirect', ['code' => 'abcd', 'state' => 'abcd']);
        $this->assertSelectorTextContains('p', 'Le paramètre state n\'est pas valide', '/auth/redirect invalid state');
        
        unset($_ENV['FLOW']);

        $client->request('GET', '/auth/redirect', ['code' => $jsonResponseCode['user_code'], 'state' => $redirect_params['state']]);
        //dump($client->getResponse()->getContent());
        $this->assertSelectorTextContains('p', 'Le paramètre usage_point_id manque dans la requête', '/auth/redirect usage_point_id missing (FLOW!=DEVICE)');

        $client->request('GET', '/auth/redirect', ['code' => $jsonResponseCode['user_code'], 'state' => $redirect_params['state'], 'usage_point_id' => $usage_point_id]);
        //dump($client->getResponse()->getContent());
        $this->assertAnySelectorTextContains('h2', 'Le consentement a bien été obtenu pour le plugin DomoticzLinky ! Vous pouvez fermer cette page et retourner sur Domoticz.', '/auth/redirect signed in (FLOW!=DEVICE)');

        $client->request('POST', '/device/token', ['client_id' => 'test', 'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code', 'device_code' => $jsonResponseCode['device_code']]);
        //dump($client->getResponse()->getContent());
        $jsonResponseToken = json_decode($client->getResponse()->getContent(), true);
        $this->assertResponseIsSuccessful('/device/token response');
        $this->assertArrayHasKey('access_token', $jsonResponseToken, '/device/token access_token in array');

        $client->request('POST', '/device/token', ['client_id' => 'test', 'grant_type' => 'refresh_token', 'refresh_token' => $jsonResponseToken['refresh_token'], 'usage_points_id' => $jsonResponseToken['usage_points_id']]);
        $jsonResponseRefreshToken = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals($jsonResponseRefreshToken['error_description'], 'Bad client_id', '/device/token Bad client_id');
        
        $client->request('POST', '/device/token', ['client_id' => $client_id, 'grant_type' => 'refresh_token', 'refresh_token' => '456']);
        $jsonResponseRefreshToken = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals($jsonResponseRefreshToken['error_description'], 'Missing usage_points_id', '/device/token Missing usage_points_id');
        
        $client->request('POST', '/device/token', ['client_id' => $client_id, 'grant_type' => 'refresh_token', 'usage_points_id' => $jsonResponseToken['usage_points_id']]);
        $jsonResponseRefreshToken = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals($jsonResponseRefreshToken['error_description'], 'Missing refresh_token', '/device/token Missing refresh_token');
        
        $client->request('POST', '/device/token', ['client_id' => $client_id, 'grant_type' => 'refresh_token', 'refresh_token' => '12314', 'usage_points_id' => $jsonResponseToken['usage_points_id']]);
        $jsonResponseRefreshToken = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals($jsonResponseRefreshToken['error_description'], 'refresh_token not found in database', '/device/token refresh_token not found in database');

        $client->request('POST', '/device/token', ['client_id' => $client_id, 'grant_type' => 'refresh_token', 'refresh_token' => $jsonResponseToken['refresh_token'], 'usage_points_id' => '5897']);
        $jsonResponseRefreshToken = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals($jsonResponseRefreshToken['error_description'], 'refresh_token not corresponding to usage_points_id', '/device/token refresh_token not corresponding to usage_points_id');
        
        $client->request('POST', '/device/token', ['client_id' => $client_id, 'grant_type' => 'refresh_token', 'refresh_token' => $jsonResponseToken['refresh_token'], 'usage_points_id' => $jsonResponseToken['usage_points_id']]);
        //dump($client->getResponse()->getContent());
        $jsonResponseRefreshToken = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('access_token', $jsonResponseRefreshToken, '/device/token access_token in array for refresh');

        $client->request('GET', '/data/proxy/metering_data_clc/v5/consumption_load_curve');
        //dump($client->getResponse()->getContent());        
        $jsonResponseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertEquals($jsonResponseData['error_description'], 'Missing usage_point_id', '/data/proxy Missing usage_point_id');

        $_ENV['DISABLE_DATA_ENPOINT_AUTH'] = 'true';

        $client->request('GET', '/data/proxy/metering_data_clc/v5/consumption_load_curve', ['usage_point_id' => $jsonResponseToken['usage_points_id'], 'start' => '2024-01-19', 'end' => '2024-01-26']);
        //dump($client->getResponse()->getContent());        
        $jsonResponseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('meter_reading', $jsonResponseData, '/data/proxy meter_reading');

        unset($_ENV['DISABLE_DATA_ENPOINT_AUTH']);

        $client->request('GET', '/data/proxy/metering_data_clc/v5/consumption_load_curve', ['usage_point_id' => $jsonResponseToken['usage_points_id'], 'start' => '2024-01-19', 'end' => '2024-01-26']);
        //dump($client->getResponse()->getContent());        
        $jsonResponseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertResponseStatusCodeSame(Response::HTTP_NOT_FOUND, '/device/code Response::HTTP_NOT_FOUND');
        $this->assertEquals($jsonResponseData['error_description'], 'Autorization missing', '/data/proxy Autorization missing');

        $client->request('GET', '/data/proxy/metering_data_clc/v5/consumption_load_curve', ['usage_point_id' => $jsonResponseToken['usage_points_id'], 'start' => '2024-01-19', 'end' => '2024-01-26'], [],
                         ['HTTP_AUTHORIZATION' => 'Bearer sdffds']
        );
        //dump($client->getResponse()->getContent());        
        $jsonResponseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN, '/device/code Response::HTTP_NOT_FOUND');
        $this->assertEquals($jsonResponseData['error_description'], 'Access token not found', '/data/proxy Access token not found');
        
        $client->request('GET', '/data/proxy/metering_data_clc/v5/consumption_load_curve', ['usage_point_id' => $jsonResponseToken['usage_points_id'], 'start' => '2024-01-19', 'end' => '2024-01-26'], [],
                         ['HTTP_AUTHORIZATION' => 'Bearer ' . $jsonResponseToken['access_token']]
        );
        //dump($client->getResponse()->getContent());        
        $jsonResponseData = json_decode($client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('meter_reading', $jsonResponseData, '/data/proxy meter_reading');
        
        $_ENV['FLOW'] = 'DEVICE';
        
        $client->request('POST', '/device/code', [ 'client_id' => 'test' ]);
        $jsonResponseCode = json_decode($client->getResponse()->getContent(), true);
        //dump($jsonResponseCode);
        $client->request('GET', '/auth/verify_code', ['code' => $jsonResponseCode['user_code'] ]);
        $location = $client->getResponse()->headers->get('location');
        $url_parts = parse_url($location);
        $query = $url_parts['query'];
        parse_str($query, $redirect_params);
        
        $client->request('GET', '/auth/redirect', ['code' => $jsonResponseCode['user_code'], 'state' => $redirect_params['state'], 'usage_point_id' => '123456789']);
        //dump($client->getResponse()->getContent());
        $this->assertSelectorTextContains('p', 'Il y a eu une erreur', '/auth/redirect invalid access_token (FLOW==DEVICE)');
    }
}
?>
