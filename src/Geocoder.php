<?php
namespace Zedix\Geocoder;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use GuzzleHttp\Client as HttpClient;

/**
 * The Google Geocoding API has the following limits in place:
 *
 * Users of the free API:
 *   2,500 requests per 24 hour period.
 *
 * Maps for Business customers:
 *   100,000 requests per 24 hour period.
 *
 * @see https://developers.google.com/maps/documentation/geocoding/
 * @see http://www.latlong.net/convert-address-to-lat-long.html
 */
class Geocoder
{
    /**
     * Geocoding URL endpoint.
     */
    const GEOCODE_API_ENDPOINT = 'https://maps.googleapis.com/maps/api/geocode/json';

    /**
     * Cache key.
     */
    const GEOCODE_CACHE_KEY = 'googleapis-geocode-%s';

    /* @var \GuzzleHttp\Client */
    protected $httpClient;

    /** @var string */
    protected $apiKey;

    /** @var string */
    protected $address;

    /** @var string */
    protected $placeId;

    /** @var string */
    protected $postalCode;

    /** @var string */
    protected $language;

    /** @var string */
    protected $region;

    /** @var string */
    protected $country;

    /** @var int */
    protected $cacheSeconds;

    /** @var bool */
    protected $useCache;

    public function __construct(HttpClient $httpClient)
    {
        $this->httpClient = $httpClient;
        $this->apiKey = config('services.google_maps.key');
        $this->useCache = true;
        $this->cacheSeconds = 86400; // 24h (60*24*60 = 86400 seconds)
    }

    public function setApiKey(string $apiKey)
    {
        $this->apiKey = $apiKey;

        return $this;
    }

    public function setAddress(string $address)
    {
        $this->address = $address;

        return $this;
    }

    /**
     * Retrieves an address for a Place ID.
     *
     * @see https://developers.google.com/maps/documentation/geocoding/intro#place-id
     */
    public function setPlaceId(string $placeId)
    {
        $this->placeId = $placeId;

        return $this;
    }

    public function setPostalCode(string $postalCode)
    {
        $this->postalCode = $postalCode;

        return $this;
    }

    /**
     * The language used in the returned response.
     *
     * @see https://developers.google.com/maps/faq#languagesupport
     */
    public function setLanguage(string $language)
    {
        $this->language = $language;

        return $this;
    }

    /**
     * In a Geocoding request, you can instruct the Geocoding service to return results
     * biased to a particular region by using the region parameter.
     *
     * @see https://developers.google.com/maps/documentation/geocoding/intro#RegionCodes
     */
    public function setRegion(string $region)
    {
        $region = strtolower($region);
        if ($region === 'gb') {
            // The United Kingdom's ccTLD is "uk" (.co.uk) while its ISO 3166-1 code is "gb"
            $region = 'uk';
        }

        $this->region = $region;

        return $this;
    }

    public function setCountry(string $country)
    {
        $this->country = $country;

        return $this;
    }

    public function setCacheSeconds($seconds)
    {
        $this->cacheSeconds = $seconds;

        return $this;
    }

    public function withoutCache()
    {
        $this->useCache = false;

        return $this;
    }

    /**
     * Address parts includes:
     * - Country
     * - Address Line 1
     * - Address Line 2
     * - City / Town / District
     * - State / Province / County / Region
     * - ZIP / Postal Code
     *
     * Some examples of addresses:
     * - "US":{"fields":["street","apt","city","state","zipcode"],"requiredFields":["street","city","country_code","zipcode","state"],"template":"us","placeholders":{"street":"e.g. 123 Main St.","apt":"e.g. Apt #7","city":"e.g. San Francisco","state":"e.g. CA","zipcode":"e.g. 94103"}},
     * - "CA":{"fields":["street","apt","city","state","zipcode"],"requiredFields":["street","city","country_code","zipcode","state"],"template":"us","placeholders":{"street":"e.g. 11108 108th Avenue","apt":"e.g. Suite #7","city":"e.g. Edmonton","state":"e.g. Alberta","zipcode":"e.g. T5H 3Z3"}},
     * - "BR":{"fields":["street","apt","city","state","zipcode"],"requiredFields":["street","city","country_code","zipcode","state"],"template":"brazil","placeholders":{"street":"ex. Rua Bossoroca, 1","apt":"ex. apt 50","city":"ex. Campinas","state":"ex. SP","zipcode":"ex. 4377190"}},
     * - "FR":{"fields":["street","apt","zipcode","city"],"requiredFields":["street","city","country_code","zipcode"],"template":"france","placeholders":{"street":"ex : 27 rue Jean Goujon","apt":"ex : Bât. B","city":"ex : Paris","zipcode":"ex : 75010"}},
     * - "DE":{"fields":["street","apt","city","zipcode"],"requiredFields":["street","city","country_code","zipcode"],"template":"germany","placeholders":{"street":"z. B Kurfürstendamm 67","apt":"z. B Gebäude 1","city":"z. B Berlin","zipcode":"z. B 10719"}},
     * - "GB":{"fields":["street","apt","city","state","zipcode"],"requiredFields":["street","city","country_code","zipcode"],"template":"england","placeholders":{"street":"e.g. 20 Deans Yd","apt":"e.g. Apart. 2","city":"e.g. London","state":"e.g. Greater London","zipcode":"e.g. SW1P 3PA"}},
     * - "ES":{"fields":["street","apt","city","zipcode","state"],"requiredFields":["street","city","country_code","zipcode","state"],"template":"spain","placeholders":{"street":"ej.: Gran Vía, 41","apt":"ej.: 2º 4ª","city":"ej.: Madrid","state":"ej.: Madrid","zipcode":"ej.: 28013"}},
     * - "IL":{"fields":["street","apt","city","zipcode"],"requiredFields":["street","city","country_code"],"template":"israel","placeholders":{"street":"ex. 7 Bloch","apt":"ex. apartment 1","city":"ex. Tel Aviv","zipcode":"ex. 64312"}},
     * - "NL":{"fields":["street","apt","zipcode","city"],"requiredFields":["street","city","country_code","zipcode"],"template":"netherlands","placeholders":{"street":"b.v. Kerklaan 1","apt":"b.v. Gebouw A","city":"b.v. Amsterdam","zipcode":"b.v. 1234 AB"}},
     * - "DK":{"fields":["street","apt","zipcode","city"],"requiredFields":["street","city","country_code","zipcode"],"template":"netherlands","placeholders":{"street":"f.eks.: Gæstgivergade 1","apt":"f.eks.: stuen","city":"f.eks.: København K","zipcode":"f.eks.: 1000"}},
     * - "IT":{"fields":["street","apt","city","state","zipcode"],"requiredFields":["street","city","country_code","zipcode","state"],"template":"italy","placeholders":{"street":"ad es. Via Garibaldi, 90","apt":"ad es. Int. 21","city":"ad es. Milano","state":"ad es. (MI)","zipcode":"ad es. 20121"}},
     * - "AU":{"fields":["street","apt","city","state","zipcode"],"requiredFields":["street","city","country_code","zipcode","state"],"template":"australia","placeholders":{"street":"e.g. 123 Main St","apt":"e.g. Unit 401","city":"e.g. Surry Hills","state":"e.g. NSW","zipcode":"e.g. 2010"}},
     * - "JP":{"disable_autocomplete":true,"fields":["zipcode","state","city","street","apt"],"requiredFields":["street","city","country_code","zipcode","state"],"template":"japan","placeholders":{"street":"例）銀座1丁目１−１","apt":"例）101号室","city":"例）中央区","state":"例）東京都","zipcode":"例）123-4567"}},
     * - "KR":{"disable_autocomplete":true,"fields":["state","city","street","apt","zipcode"],"requiredFields":["street","city","country_code","zipcode","state"],"template":"korea","placeholders":{"street":"예) 언주로 406","apt":"예) 개나리 아파트","city":"예) 강남구","state":"예) 서울시","zipcode":"예) 135-986"}},
     * - "CN":{"disable_autocomplete":true,"fields":["state","city","zipcode","street","apt"],"requiredFields":["street","city","country_code","zipcode","state"],"template":"china","placeholders":{"street":"例如）山东省","apt":"例如）莲花小区","city":"例如）青岛市","state":"例如）山东省","zipcode":"例如）266100"}},
     * - "HK":{"disable_autocomplete":true,"fields":["state","city","street","apt"],"requiredFields":["street","city","country_code","state"],"template":"hong_kong","placeholders":{"street":"例如) 廣東道88號","apt":"例如) 雅佳大廈","city":"例如) 尖沙咀","state":"例如) 九龍"}},
     * - "IE":{"fields":["street","apt","city","state","zipcode"],"requiredFields":["street","city","country_code"],"template":"ireland","placeholders":{"street":"e.g. 12 Drumcondra Road","apt":"e.g. Apt. 2","city":"e.g. Dublin","state":"e.g. Galway","zipcode":"e.g. 14"}},
     * - "DEFAULT":{"fields":["street","apt","city","state","zipcode"],"requiredFields":["street","city","country_code"],"placeholders":{"street":"House name/number + street/road","apt":"Apt., suite, building access code"}}},
     */
    public function get()
    {
        $url = $this->getUrl();

        if ($this->useCache) {
            $cacheKey = sprintf(self::GEOCODE_CACHE_KEY, sha1($url));
            $addressInfo = Cache::get($cacheKey);
            if (is_array($addressInfo) && isset($addressInfo['lat'])) {
                return $addressInfo;
            }
        }

        $response = $this->httpClient->get($url);

        $addressInfo = $this->formatResponse($response, $url);
        if ($this->useCache) {
            Cache::put($cacheKey, $addressInfo, $this->cacheSeconds);
        }

        return $addressInfo;
    }

    protected function getUrl()
    {
        $url = self::GEOCODE_API_ENDPOINT . '?key=' . $this->apiKey;

        if ($this->placeId) {
            $url .= '&place_id=' . $this->placeId;
        }

        if ($this->address) {
            $url .= '&address=' . urlencode($this->address);
        }

        if ($this->language) {
            $url .= '&language=' . $this->language;
        }

        if ($this->region) {
            $url .= '&region=' . $this->region;
        }

        if ($this->country) {
            $url .= '&components=country:' . strtolower($this->country);

            if ($this->postalCode) {
                $postalCode = preg_replace('/\s+/', '', $this->postalCode);
                if ($this->isPostalCode($postalCode)) {
                    $url .= '|postal_code:' . urlencode($postalCode);
                }
            }
        }

        return $url;
    }

    protected function getAddressComponent($addressComponent, $type, $short = true)
    {
        foreach ($addressComponent as $component) {
            if (in_array($type, $component->types)) {
                return $short ? $component->short_name : $component->long_name;
            }
        }
        return null;
    }

    /**
     * @see https://github.com/axlon/laravel-postal-code-validation
     */
    public function isPostalCode($postalCode)
    {
        return (bool) preg_match('/^[0-9\-]+$/', $postalCode);
    }

    protected function formatResponse($response, $url)
    {
        $addressInfo = false;

        $data = json_decode($response->getBody());
        if ($data) {
            if (!empty($data->results)) {
                $result = $data->results[0];

                // https://developers.google.com/maps/documentation/geocoding/intro#Types
                $addressType = is_array($result->types) ? $result->types[0] : '';

                $addressInfo = array(
                    'place_id' => $result->place_id, // e.g. 'ChIJK8bObYYvBEgRcLIJHlI3DQQ'
                    'lat' => $result->geometry->location->lat,
                    'lng' => $result->geometry->location->lng,
                    'address' => trim($this->getAddressComponent($result->address_components, 'street_number') . ' ' . $this->getAddressComponent($result->address_components, 'route')),
                    'country' => $this->getAddressComponent($result->address_components, 'country', true),
                    'country_long' => $this->getAddressComponent($result->address_components, 'country', false),
                    'postal_code' => $this->getAddressComponent($result->address_components, 'postal_code'),
                    'neighborhood' => $this->getAddressComponent($result->address_components, 'neighborhood'),
                    'sublocality' => $this->getAddressComponent($result->address_components, 'sublocality'),
                    'locality' => $this->getAddressComponent($result->address_components, 'locality'),
                    'administrative_area_level_1' => $this->getAddressComponent($result->address_components, 'administrative_area_level_1', false),
                    'administrative_area_level_2' => $this->getAddressComponent($result->address_components, 'administrative_area_level_2', false),
                    'administrative_area_level_3' => $this->getAddressComponent($result->address_components, 'administrative_area_level_3', false),
                    'geometry' => $result->geometry,
                    'type' => $addressType,
                    'url' => $url,
                );
            } else {
                // REQUEST_DENIED => Requests to this API must be over SSL.
                // REQUEST_DENIED => This API project is not authorized to use this API.
                // OVER_QUERY_LIMIT => You have exceeded your daily request quota for this API.

                // If Quota is exceeded:
                // object(stdClass) {
                //     ["error_message"] => "You have exceeded your daily request quota for this API."
                //     ["results"] => array(0) {}
                //     ["status"] => "OVER_QUERY_LIMIT"
            }
        }

        return $addressInfo;
    }
}
