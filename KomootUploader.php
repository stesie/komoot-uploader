<?php

use Symfony\Component\BrowserKit\HttpBrowser;
use Symfony\Component\BrowserKit\Response;
use Symfony\Component\HttpClient\HttpClient;

class KomootUploader
{
    const SIGN_IN_URI = 'https://account.komoot.com/v1/signin';
    const SIGN_IN_TRANSFER_URI = 'https://account.komoot.com/actions/transfer?type=signin';
    const UPLOAD_GPX_URI = 'https://www.komoot.de/api/routing/import/files/?data_type=gpx';
    const QUERY_ROUTER_URI_TPL = 'https://www.komoot.de/api/routing/import/tour?sport=%s&_embedded=way_types%%2Csurfaces%%2Cdirections%%2Ccoordinates';
    const CREATE_TOUR_URI = 'https://www.komoot.de/api/v007/tours/?hl=de';

    private HttpBrowser $browser;

    public function __construct(string $email, string $password)
    {
        $this->browser = new HttpBrowser(HttpClient::create());
        $this->browser->followRedirects(true);

        $this->login($email, $password);
    }

    private function login(string $email, string $password)
    {
        $this->browser->request('POST', self::SIGN_IN_URI, [
            'email' => $email,
            'password' => $password
        ]);

        /** @var Response $loginResponse */
        $loginResponse = $this->browser->getResponse();
        $responseData = json_decode($loginResponse->getContent(), true);

        if (!isset($responseData['type']) || $responseData['type'] !== 'logged_in') {
            throw new KomootUploaderException(
                'Unable to login user. Invalid response data: ' . $loginResponse->getContent()
            );
        }

        $this->browser->request('GET', self::SIGN_IN_TRANSFER_URI);

        /** @var Response $transferLoginResponse */
        $transferLoginResponse = $this->browser->getResponse();

        if ($transferLoginResponse->getStatusCode() !== 200) {
            throw new KomootApiClientException(
                'Unable to transfer login. Invalid status code given: ' . $transferLoginResponse->getStatusCode()
            );
        }
    }

    public function uploadPlannedTour(string $gpxContent, string $sport, string $name)
    {
        $routerInput = $this->uploadGpx($gpxContent);
        $routerInput->name = $name;
        $routerInput->sport = $sport;

        $routerResult = $this->queryRouter(\json_encode($routerInput), $sport);
        $routerResult->status = 'private';

        return $this->createTour(\json_encode($routerResult));
    }

    private function uploadGpx(string $gpxContent)
    {
        $this->browser->request('POST', self::UPLOAD_GPX_URI, [], [], [], $gpxContent);

        /** @var Response $response */
        $response = $this->browser->getResponse();

        if ($response->getStatusCode() !== 201) {
            throw new KomootUploaderException(
                \sprintf('GPX-Upload failed. Unexpected HTTP status code %d: %s', $response->getStatusCode(), $response->getContent())
            );
        }

        $json = \json_decode($response->getContent());

        if (\count($json->_embedded->items) !== 1) {
            throw new KomootUploaderException(
                'GPX-Upload failed. Unexpected number of embedded items: ' . $response->getContent()
            );
        }

        return $json->_embedded->items[0];
    }

    private function queryRouter(string $routerInput, string $sport)
    {
        $uri = \sprintf(self::QUERY_ROUTER_URI_TPL, $sport);
        $this->browser->request('POST', $uri, [], [], [
            'HTTP_CONTENT_TYPE' => 'application/json'
        ], $routerInput);

        /** @var Response $response */
        $response = $this->browser->getResponse();

        if ($response->getStatusCode() !== 200) {
            throw new KomootUploaderException(
                \sprintf('Router query failed. Unexpected HTTP status code %d: %s', $response->getStatusCode(), $response->getContent())
            );
        }

        $json = \json_decode($response->getContent());
        return $json->_embedded->original;
    }

    private function createTour(string $tourInput)
    {
        file_put_contents('tour_input.json', $tourInput);

        $this->browser->request('POST', self::CREATE_TOUR_URI, [], [], [
            'HTTP_CONTENT_TYPE' => 'application/json'
        ], $tourInput);

        /** @var Response $response */
        $response = $this->browser->getResponse();

        if ($response->getStatusCode() !== 201) {
            throw new KomootUploaderException(
                \sprintf('Tour creation failed. Unexpected HTTP status code %d: %s', $response->getStatusCode(), $response->getContent())
            );
        }

        $json = \json_decode($response->getContent());
        return $json->id;
    }
}