# @zedix/geocoder

[![version](https://img.shields.io/github/release/zedix/geocoder.svg?style=flat-square)](https://github.com/zedix/geocoder/releases)
![tag](https://img.shields.io/github/tag/zedix/geocoder.svg?maxAge=60)
[![downloads](https://img.shields.io/packagist/dt/zedix/geocoder.svg?style=flat-square)](https://packagist.org/packages/zedix/geocoder)

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
