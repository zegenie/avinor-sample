<?php

namespace AppBundle\Controller;

use GuzzleHttp\Client;
use Nathanmac\Utilities\Parser;
use Sabre\Xml\Service;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

class DefaultController extends Controller
{

    /**
     * Get and cache a list of airports so we can display it to the user
     *
     * @return array
     */
    protected function getAirportList()
    {
        $cache = new FilesystemAdapter();
        $airportCache = $cache->getItem('airportcache.list');

        if (!$airportCache->isHit()) {
            $client   = $this->container->get('guzzle.avinor.client');
            $response = $client->get('/airportNames.asp');
            $result   = $response->getBody()->getContents();

            $service  = new Service();
            $airports = $service->parse($result);

            $airportCache->set($airports);
            $cache->save($airportCache);
        }

        return $airportCache->get();
    }

    /**
     * Default route that shows a list of airports to pick from
     *
     * @Route("/", name="homepage")
     */
    public function indexAction(Request $request)
    {
        try {
            $airports = $this->getAirportList();

            return $this->render('default/index.html.twig', [
                'airports' => $airports
            ]);
        } catch (\Exception $e) {

            return $this->render('default/error.html.twig', [
                'message' => 'An error occurred when showing the list of airports. Please try again later.'
            ]);
        }
    }

    /**
     * Route action to show a list of flights from a specific airport
     *
     * @Route("/{airportCode}", name="airport")
     */
    public function airportAction(Request $request)
    {
        $airportCode = $request->get('airportCode');

        try {
            $client = $this->container->get('guzzle.avinor.client');
            $response = $client->get('/XmlFeed.asp', [
                'query' => [
                    'timeFrom' => 2,
                    'timeTo' => 2,
                    'airport' => $airportCode
                ]
            ]);

            $result = $response->getBody()->getContents();
            $parser = new Parser\Parser();
            $flightData = $parser->xml($result);
            $flights = $flightData['flights']['flight'];

            $delayedFlights = [];

            foreach ($flights as $flight) {
                if (isset($flight['status']) && $flight['status']['@code'] == 'E') {
                    $newTime = new \DateTime($flight['schedule_time']);
                    $newTime->setTimezone(new \DateTimeZone('Europe/Oslo'));
                    $flight['newTime'] = $newTime->format('H:i');
                    $delayedFlights[] = $flight;
                }
            }

            return $this->render('default/airport.html.twig', [
                'flights' => $delayedFlights
            ]);
        } catch (\Exception $e) {

            return $this->render('default/error.html.twig', [
                'message' => 'An error occurred while retrieving flight data. Please try again later.'
            ]);
        }

    }
}
