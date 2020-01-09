OAuth 2.0 Device Flow Proxy Server for Enedis
=============================================

An OAuth 2.0 Device Code flow for [DomoticzLinky](https://github.com/guillaumezin/DomoticzLinky), a [Domoticz](https://www.domoticz.com/) plugin, adapted to [Enedis data hub](https://datahub-enedis.fr)

This project if a fork of https://github.com/aaronpk/Device-Flow-Proxy-Server, a big thanks to him.

This service acts as an OAuth server that implements the device code flow, proxying to a real OAuth server behind the scenes.

Compared to the original project, this implementation uses MongoDB instead of Redis (because it was easier for me to find a serious and free provider with MongoDB), it adds a feature to provide client_secret from .env file instead of getting it from device request, to keep it private, and it can act as a proxy to add this client_secret from `.env` file to Enedis, if you don't want the device to provide it.

Installation
------------

```
composer install
cp .env.example .env
```

In the `.env` file, fill out the required variables.

You will need to install MongoDB if it is not already on your system, or point to an existing MongoDB server in the config file.

Define your OAuth server's authorization endpoint and token endpoint URL, and optionaly the client_secret, this way it will be kept private between your web server and Enedis, otherwise the device must provide it during requests.


Usage
-----

The device will need to register an application at the OAuth server to get a client ID. You'll need to set the proxy's URL as the callback URL in the OAuth application registration:

```
http://localhost:8080/auth/redirect
```

The device can begin the flow by making a POST request to this proxy:

```
curl http://localhost:8080/device/code -d client_id=1234567890
```

or if your device must provide client_secret (otherwise you can specify it in the `.env` file)

```
curl http://localhost:8080/device/code -d client_id=1234567890 -d client_secret=12345678-1234-1234-1234-1234567890ab
```

The response will contain the URL the user should visit and the code they should enter, as well as a long device code.

```json
{
    "device_code": "5cb3a6029c967a7b04f642a5b92b5cca237ec19d41853f55dcce98a4d2aa528f",
    "user_code": "248707",
    "verification_uri": "http://localhost:8080/device",
    "expires_in": 300,
    "interval": 5
}
```

The device should instruct the user to visit the URL and enter the code, or can provide a full link that pre-fills the code for the user in case the device is displaying a QR code.

`http://localhost:8080/device?code=248707`

The device should then poll the token endpoint at the interval provided, making a POST request like the below:

```
curl http://localhost:8080/device/token -d grant_type=urn:ietf:params:oauth:grant-type:device_code \
  -d client_id=1234567890 \
  -d device_code=5cb3a6029c967a7b04f642a5b92b5cca237ec19d41853f55dcce98a4d2aa528f
```

While the user is busy logging in, the response will be

```
{"error":"authorization_pending"}
```

Once the user has finished logging in and granting access to the application, the response will contain an access token.

```json
{
  "access_token": "8YQZTbKML5Ntx2iuzdBJTvWE4XzIlSHeYmu4Y1GVpjrft2q768wavr",
  "refresh_token": "QcMhancv1wPyi8uwnkzcTNyd397oC7K0La8otPcssYMpXT",
  "token_type": "Bearer",
  "expires_in": 12600,
  "usage_point_id" : "1234567890abcd"
}
```

If the client_secret is not known by the device but is configured in the `.env` file, you can refresh the token with:

```
curl http://localhost:8080/device/proxy -d grant_type=refresh_token \
  -d client_id=1234567890 \
  -d refresh_token=QcMhancv1wPyi8uwnkzcTNyd397oC7K0La8otPcssYMpXT
```

You'll get a response with new access and refresh tokens.


```json
{
  "access_token": "6czyedyLUHvyjtWZuWwBLkXNZhzk9QLP9Cip5NPhFNmc8znWoPipnW",
  "refresh_token": "YpcX7v7sohTvDTWfzZpj4DyfZgvYtJKdNj7YHEhr3ZH7FCiqSDCDJ2",
  "token_type": "Bearer",
  "expires_in": 12600,
  "usage_point_id" : "1234567890abcd"
}
```
