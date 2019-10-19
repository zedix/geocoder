# @zedix/geocooder

![version](https://img.shields.io/github/package-json/v/zedix/geocoder.svg?maxAge=60)
![tag](https://img.shields.io/github/tag/zedix/geocoder.svg?maxAge=60)

## Usage

```php
$client = new \GuzzleHttp\Client();

$geocoder = new Geocoder($client);

$geocoder
    ->setLanguage('fr')
    ->setCountry('FR')
    ->setPostalCode(75019)
    ->setAddress('1 avenue Jean Jaurès, 75019 Paris')
    ->get();
```
