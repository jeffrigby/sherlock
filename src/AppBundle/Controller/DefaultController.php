<?php

namespace AppBundle\Controller;

use GuzzleHttp\Exception\ClientException;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use AppBundle\Entity\Image;
use AppBundle\Form\ImageType;
use Symfony\Component\Filesystem\Filesystem;
use GuzzleHttp\Client;
use GuzzleHttp\Promise;
use Symfony\Component\HttpFoundation\JsonResponse;
use DarrynTen\Clarifai\Clarifai;
use Symfony\Component\Cache\Simple\FilesystemCache;
use Aws\S3\S3Client;
use Aws\Rekognition\RekognitionClient;
;



class DefaultController extends Controller
{
    /**
     * @Route("/", name="homepage")
     */
    public function indexAction(Request $request)
    {
        $image = new Image();
        $form = $this->createForm(ImageType::class, $image);
        $form->handleRequest($request);
        $em = $this->get('doctrine')->getManager();

        if ($form->isSubmitted() && $form->isValid()) {
            // $file stores the uploaded Image file
            /** @var Symfony\Component\HttpFoundation\File\UploadedFile $file */
            $file = $image->getImage();

            $file->move(
              $this->getParameter('images_directory'),
              $file->getClientOriginalName()
            );

            $image->setImage($file->getClientOriginalName());
            $em->persist($image);
            $em->flush();
            return $this->redirect("/report/{$image->getId()}");
        }

        // replace this example code with whatever you need
        return $this->render('default/index.html.twig', [
            'base_dir' => realpath($this->getParameter('kernel.root_dir').'/..').DIRECTORY_SEPARATOR,
            'form' => $form->createView(),
            'images' => $this->getImages()
        ]);
    }

    private function getImages() {
      $em = $this->get('doctrine')->getManager();
      $repository = $em->getRepository('AppBundle:Image');
      $images = $repository->findBy(array(), array('image'=>'asc'));
      return $images;
    }

    /**
     * @Route("/pricing", name="pricing")
     */
    public function pricingAction() {
      return $this->render('default/pricing.html.twig', [
        'base_dir' => realpath($this->getParameter('kernel.root_dir').'/..').DIRECTORY_SEPARATOR,
      ]);
    }

    /**
     * @Route("/meta", name="meta")
     */
    public function metaAction() {
      $q = !empty($_GET['q']) ? $_GET['q'] : NULL;
      $bing = '';
      $google = '';

      $client = $this->getGuzzleClient();
      if ($q) {
        $headers = [
          'Ocp-Apim-Subscription-Key' => $this->getParameter('bing_search_key'),
        ];

        $parameters = ['q' => $q];

        $response = $client->get(
          'https://api.cognitive.microsoft.com/bing/v5.0/search',
          [
            'headers' => $headers,
            'query'   => $parameters,
          ]
        );

        $bing = json_decode($response->getBody(), TRUE);
        $bing = json_encode($bing, JSON_PRETTY_PRINT);


        // Google
        $parameters = [
          'q' => $q,
          'key' => $this->getParameter('google_search_key'),
          'cx' => $this->getParameter('google_search_cx')
        ];

        $response = $client->get(
          'https://www.googleapis.com/customsearch/v1',
          [
            'headers' => $headers,
            'query'   => $parameters,
          ]
        );

        $google = json_decode($response->getBody(), TRUE);
        $google = json_encode($google, JSON_PRETTY_PRINT);
      }

      return $this->render('default/metadata.html.twig', [
        'q' => $q,
        'base_dir' => realpath($this->getParameter('kernel.root_dir').'/..').DIRECTORY_SEPARATOR,
        'bing' => $bing,
        'google' => $google
      ]);
    }

    /**
     * @Route("/delete/{id}", requirements={"id" = "\d+"}, name="deleteImage")
     */
    public function deleteImageAction($id) {
      $em = $this->get('doctrine')->getManager();
      $repository = $em->getRepository('AppBundle:Image');
      $product = $repository->find($id);
      $img_dir = $this->getParameter('images_directory');
      $image_path = $img_dir . '/' . $product->getImage();
      $fs = new Filesystem();
      $fs->remove($image_path);
      $em->remove($product);
      $em->flush();
      return $this->redirect($this->generateUrl('homepage'));
    }

    /**
     * @Route("/report/{id}", requirements={"id" = "\d+"}, name="reportImage")
     */
    public function reportImageAction($id) {
      $em = $this->get('doctrine')->getManager();
      $repository = $em->getRepository('AppBundle:Image');
      $image = $repository->find($id);
      $image_path = '/uploads/' . $image->getImage();

      return $this->render('default/report.html.twig', [
        'base_dir' => realpath($this->getParameter('kernel.root_dir').'/..').DIRECTORY_SEPARATOR,
        'image_path' => $image_path,
        'image_id' => $id,
        'reset' => !empty($_GET['reset']) ? 'true' : 'false',
        'sections' => ['google', 'clarifai', 'microsoft', 'imagga', 'cloudsite']
      ]);
    }

    /**** Amazon *****/
    /**
     * Connect to CloudSite
     * @Route("/api/amazon/{id}", requirements={"id" = "\d+"}, name="apiAmazon")
     */
    public function amazonAction($id) {
      $cache = $this->getCache();
      $cache_key = "amazon.response.$id";

      if ($cached = $this->getCacheItem($cache_key)) {
        return $cached;
      }

      $imagePath = $this->getImagePath($id);
      $image = file_get_contents($imagePath);

      $settings = [
        'version' => 'latest',
        'region'  => 'us-east-1',
        'credentials' => [
          'key'    => $this->getParameter('amazon_key'),
          'secret' => $this->getParameter('amazon_secret'),
        ],
      ];

      $rekognition = new RekognitionClient($settings);

      $result = $rekognition->detectLabels([
        'Image' => array(
          'Bytes' => $image,
        ),
          'Attributes' => array('ALL')
       ]);


      print_r($result);

    }

    /**** CloudSite *****/
    /**
     * Connect to CloudSite
     * @Route("/api/cloudsite/{id}", requirements={"id" = "\d+"}, name="apiCloudsite")
     */
    public function cloudsiteAction($id) {
      $cache = $this->getCache();
      $cache_key = "cloudsite.response.$id";
      $cache_token_key = "cloudsite.token.$id";

      if ($cached = $this->getCacheItem($cache_key)) {
        return $cached;
      }

      $imagePath = $this->getImagePath($id);
      $image = file_get_contents($imagePath);
      $client = $this->getGuzzleClient();
      $fileinfo = pathinfo($imagePath);
      $tags = [];

      if (empty($_GET['reset']) && $cache->has($cache_token_key)) {
        $tags['uploaded_file'] = $cache->get("cloudsite.token.$id");
        $tags['uploaded_file']['cached'] = TRUE;
      }
      else {
        try {
          $res = $client->post(
            'https://api.cloudsightapi.com/image_requests',
            [
              'headers'   => ['Authorization' => 'CloudSight ' . $this->getParameter('cloudsight_key')],
              'multipart' => [
                [
                  'name'     => 'image_request[image]',
                  'contents' => $image,
                  'filename' => time() . '.' . $fileinfo['extension']
                ],
                [
                  'name'     => 'image_request[locale]',
                  'contents' => 'en-US'
                ]
              ]
            ]
          );

          $uploaded_file = json_decode($res->getBody(), TRUE);
          $tags['uploaded_file'] = $uploaded_file;
          $cache->set($cache_token_key, $uploaded_file);
          $tags['uploaded_file']['cached'] = FALSE;
        } catch (ClientException $e) {
          $tags['uploaded_file']['error'] = TRUE;
          $tags['uploaded_file']['message'] = $e->getMessage();
        }
      }

      // This API takes a while to tag
      sleep(10);

      if (!empty($tags['uploaded_file']['token'])) {
        try {
          $res = $client->get("https://api.cloudsightapi.com/image_requests/" . $tags['uploaded_file']['token'], [
            'headers' => ['Authorization' => 'CloudSight ' . $this->getParameter('cloudsight_key')],
          ]);
          $returned_tags = json_decode($res->getBody(), TRUE);
          $tags['tags'] = $returned_tags;

        }
        catch (ClientException $e) {
          $tags['tags']['error'] = TRUE;
          $tags['tags']['message'] = $e->getMessage();
        }
      }

      if ($tags['tags']['status'] === 'completed') {
        $tags['simple']['header'][] = '<p>' . $tags['tags']['name'] . '</p>';
        $cache->set($cache_key, $tags);
      }
      $tags['cached'] = FALSE;
      $response = new JsonResponse($tags);
      $response->setEncodingOptions($response->getEncodingOptions() | JSON_PRETTY_PRINT);
      return $response;
    }

    /***** Microsoft ****/

    /**
     * Connect to imagga
     * @Route("/api/microsoft/{id}", requirements={"id" = "\d+"}, name="apiMicrosoft")
     */
    public function microsoftAction($id) {
      $cache = $this->getCache();
      $cache_key = "microsoft.response.$id";

      if ($cached = $this->getCacheItem($cache_key)) {
        return $cached;
      }

      $imagePath = $this->getImagePath($id);
      $imageData = file_get_contents($imagePath);
      $tags = [];

      $client = $this->getGuzzleClient();

      $headers = [
        'Content-Type' => 'application/octet-stream',
        'Ocp-Apim-Subscription-Key' => $this->getParameter('microsoft_cog_key'),
      ];

      $analyze = [
        'Tags', 'Description', 'Faces', 'ImageType', 'Color', 'Adult'
      ];

      $parameters = [
        'visualFeatures' => implode(',', $analyze)
      ];

      try {
        $response = $client->post(
          'https://eastus2.api.cognitive.microsoft.com/vision/v1.0/analyze',
          [
            'headers' => $headers,
            'query' => $parameters,
            'body' => $imageData,
          ]
        );

        $json = json_decode($response->getBody(), TRUE);
        $tags['json'] = $json;
      } catch (ClientException $e) {
        $tags['error'] = TRUE;
        $tags['message'] = $e->getMessage();
      }

      // Set the simple tags
      $simple = [];
      if (!empty($tags['json']['tags'])) {
        foreach ($tags['json']['tags'] as $t) {
          $simple['Tags'][] = [
            'tag' => $t['name'],
            'score' => $t['confidence'],
          ];
        }
      }

      if (!empty($tags['json']['description']['captions'][0]['text'])) {
        $simple['header'][] = "Description: {$tags['json']['description']['captions'][0]['text']}";
        $simple['header'][] = "Confidence: {$tags['json']['description']['captions'][0]['confidence']}";
      }

      if (is_array($tags['json']['description']['tags'])) {
        $simple['header'][] = 'Tags: ' . implode(', ', $tags['json']['description']['tags']);
      }

      $tags['simple'] = $simple;

      $cache->set($cache_key, $tags);
      $tags['cached'] = FALSE;
      $response = new JsonResponse($tags);
      $response->setEncodingOptions($response->getEncodingOptions() | JSON_PRETTY_PRINT);
      return $response;

    }


    /***** Imagaa *******/
    /**
     * Connect to imagga
     * @Route("/api/imagga/{id}", requirements={"id" = "\d+"}, name="apiImagga")
     */
    public function imaggaAction($id) {
      $cache = $this->getCache();
      $cache_key = "imagga.response.$id";

      if ($cached = $this->getCacheItem($cache_key)) {
        return $cached;
      }

      $imagePath = $this->getImagePath($id);
      $image = file_get_contents($imagePath);
      $client = $this->getGuzzleClient();
      $fileinfo = pathinfo($imagePath);
      $auth = [$this->getParameter('imagga_user'), $this->getParameter('imagga_pass')];
      $tags = [];

      try {
        $res = $client->post(
          'https://api.imagga.com/v1/content',
          [
            'auth'    => $auth,
            'headers' => ['Accept' => 'application/json'],
            'multipart' => [
              [
                'name'     => 'image',
                'contents' => $image,
                'filename' => time() . '.' . $fileinfo['extension']
              ],
              [
                'name'     => 'type',
                'contents' => mime_content_type($imagePath)
              ]
            ]
          ]
        );

        $uploaded_file = json_decode($res->getBody());
        $tags['uploaded_file'] = $uploaded_file;
        $file_id = $uploaded_file->uploaded[0]->id;

      }
      catch (ClientException $e) {
        $tags['uploaded_file']['error'] = TRUE;
        $tags['uploaded_file']['message'] = $e->getMessage();
      }

      if (!empty($file_id)) {
        try {
          $tagging = $client->get(
            'https://api.imagga.com/v1/tagging?content=' . $file_id,
            ['auth' => $auth]
          );
          $tags['tagging'] = json_decode($tagging->getBody(), TRUE);
        } catch (ClientException $e) {
          $tags['tagging']['error'] = TRUE;
          $tags['tagging']['message'] = $e->getMessage();
        }
      }
//
//      try {
//        $categorizers = $client->get('https://api.imagga.com/v1/categorizers', ['auth' => $auth]);
//        $tags['categorizers'] = json_decode($categorizers->getBody());
//      }
//      catch (ClientException $e) {
//        $tags['categorizers']['error'] = TRUE;
//        $tags['categorizers']['message'] = $e->getMessage();
//      }

      $simple = [];
      if (!empty($tags['tagging']['results'][0]['tags'])) {
        foreach ($tags['tagging']['results'][0]['tags'] as $t) {
          $simple['Tags'][] = [
            'tag' => $t['tag'],
            'score' => $t['confidence'],
          ];
        }
      }

      $tags['simple'] = $simple;


      $cache->set($cache_key, $tags);
      $tags['cached'] = FALSE;

      $response = new JsonResponse($tags);
      $response->setEncodingOptions($response->getEncodingOptions() | JSON_PRETTY_PRINT);
      return $response;
    }

    /***** Clarifai *******/

    /**
     * Connect to the Google Client and match all types.
     * @Route("/api/clarifai/{id}", requirements={"id" = "\d+"}, name="apiClarifai")
     */
    public function clarifaiAction($id) {
      $cache = $this->getCache();
      $cache_key = "clarifai.response.$id";

      if ($cached = $this->getCacheItem($cache_key)) {
        return $cached;
      }

      $imagePath = $this->getImagePath($id);
      $image = file_get_contents($imagePath);
      $image64 = base64_encode($image);
      $clarifai = new Clarifai(
        $this->getParameter('clarifai_key'),
        $this->getParameter('clarifai_secret')
      );

      $models = [
        'GENERAL' => \DarrynTen\Clarifai\Repository\ModelRepository::GENERAL,
        'FOOD' => \DarrynTen\Clarifai\Repository\ModelRepository::FOOD,
        'APPAREL' => \DarrynTen\Clarifai\Repository\ModelRepository::APPAREL,
        'CELEBRITY' => \DarrynTen\Clarifai\Repository\ModelRepository::CELEBRITY,
        'TRAVEL' => \DarrynTen\Clarifai\Repository\ModelRepository::TRAVEL,
        'FACE' => \DarrynTen\Clarifai\Repository\ModelRepository::FACE,
      ];

      $tags = [];
      $simple = [];

      foreach ($models as $key => $model) {
        $tags[$key] = $clarifai->getModelRepository()->predictEncoded(
          $image64,
          $model
        );

        if (!empty($tags[$key]['outputs'][0]['data']['concepts'])) {
          foreach ($tags[$key]['outputs'][0]['data']['concepts'] as $t) {
            $simple[$key][] = [
              'tag' => $t['name'],
              'score' => $t['value']
            ];
          }
        }

        if ($key === 'CELEBRITY') {
          if (!empty($tags[$key]['outputs'][0]['data']['regions'])) {
            $i = 1;
            foreach ($tags[$key]['outputs'][0]['data']['regions'] as $region) {
              foreach ($region['data']['face']['identity']['concepts'] as $t) {
                $celebrity_key = 'CELEBRITY ' . $i;
                $simple[$celebrity_key][] = [
                  'tag' => $t['name'],
                  'score' => $t['value']
                ];
              }
              $i++;
            }
          }
        }
      }

      $tags['simple'] = $simple;

      $cache->set($cache_key, $tags);
      $tags['cached'] = FALSE;

      $response = new JsonResponse($tags);
      $response->setEncodingOptions($response->getEncodingOptions() | JSON_PRETTY_PRINT);
      return $response;
    }

    /***** Google ******/

    /**
     * Connect to the Google Client and match all types.
     * @Route("/api/google/{id}", requirements={"id" = "\d+"}, name="apiGoogle")
     */
    public function googleVisionAction($id) {
      $cache = $this->getCache();
      $cache_key = "google.response.$id";

      if ($cached = $this->getCacheItem($cache_key)) {
        return $cached;
      }

      $imagePath = $this->getImagePath($id);
      $image = file_get_contents($imagePath);
      $image64 =  base64_encode($image);
      $types = [
        'LABEL_DETECTION',
        'TEXT_DETECTION',
        'FACE_DETECTION',
        'LANDMARK_DETECTION',
        'LOGO_DETECTION',
        'SAFE_SEARCH_DETECTION',
        'IMAGE_PROPERTIES',
        'WEB_DETECTION',

      ];
      $uri = 'https://vision.googleapis.com/v1/images:annotate?key=' . $this->getParameter('google_api_key');
      $client = $this->getGuzzleClient();


      $promises = [];
      foreach ($types as $type) {
        $promises[$type] = $client->postAsync($uri,['headers'=>['Content-Type'=>'application/json'],'body'=>$this->getGoogleJson($image64, $type)]);
      }

      $results = Promise\unwrap($promises);

      // Wait for the requests to complete, even if some of them fail
      $results = Promise\settle($promises)->wait();

      $tags = [];
      foreach ($results as $type => $request) {
        $json = (string) $request['value']->getBody();
        $jsonArr = json_decode($json, TRUE);
        $tags[$type] = $jsonArr['responses'];
      }

      $simple = [];
      if (!empty($tags['LABEL_DETECTION'][0]['labelAnnotations'])) {
        foreach ($tags['LABEL_DETECTION'][0]['labelAnnotations'] as $tag) {
          $simple['LABEL_DETECTION'][] = [
            'tag' => $tag['description'],
            'score' => $tag['score']
          ];
        }
      }

      if (!empty($tags['LANDMARK_DETECTION'][0]['landmarkAnnotations'])) {
        foreach ($tags['LANDMARK_DETECTION'][0]['landmarkAnnotations'] as $tag) {

          $simple['LANDMARK_DETECTION'][] = [
            'tag' => $tag['description'] . ' (load map)',
            'score' => $tag['score'],
            'link' => "http://maps.google.com/?q={$tag['locations'][0]['latLng']['latitude']},{$tag['locations'][0]['latLng']['longitude']}"
          ];
        }
      }

      if (!empty($tags['LOGO_DETECTION'][0]['logoAnnotations'])) {
        foreach ($tags['LOGO_DETECTION'][0]['logoAnnotations'] as $tag) {
          $simple['LOGO_DETECTION'][] = [
            'tag' => $tag['description'],
            'score' => $tag['score'],
          ];
        }
      }

      if (!empty($tags['WEB_DETECTION'][0]['webDetection']['webEntities'])) {
        foreach ($tags['WEB_DETECTION'][0]['webDetection']['webEntities'] as $tag) {
          if (empty($tag['description'])) continue;
          $simple['WEB_DETECTION-ENTITIES'][] = [
            'tag' => $tag['description'],
            'score' => !empty($tag['score']) ? $tag['score'] : NULL,
          ];
        }
      }

      if (!empty($tags['WEB_DETECTION'][0]['webDetection']['webEntities'])) {
        foreach ($tags['WEB_DETECTION'][0]['webDetection']['webEntities'] as $tag) {
          if (empty($tag['description'])) continue;
          $simple['WEB_DETECTION-ENTITIES'][] = [
            'tag' => !empty($tag['description']) ? $tag['description'] : NULL,
            'score' => !empty($tag['score']) ? $tag['score'] : NULL,
          ];
        }
      }

      if (!empty($tags['WEB_DETECTION'][0]['webDetection']['fullMatchingImages'])) {
        foreach ($tags['WEB_DETECTION'][0]['webDetection']['fullMatchingImages'] as $tag) {
          $simple['WEB_DETECTION-FULL-MATCHING'][] = [
            'tag' => !empty($tag['url']) ? $tag['url'] : NULL,
//            'score' => NULL,
            'link' => !empty($tag['url']) ? $tag['url'] : NULL
          ];
        }
      }

      if (!empty($tags['WEB_DETECTION'][0]['webDetection']['partialMatchingImages'])) {
        foreach ($tags['WEB_DETECTION'][0]['webDetection']['partialMatchingImages'] as $tag) {
          $simple['WEB_DETECTION-PARTIAL-MATCHING'][] = [
            'tag' => !empty($tag['url']) ? $tag['url'] : NULL,
//            'score' => NULL,
            'link' => !empty($tag['url']) ? $tag['url'] : NULL
          ];
        }
      }

      if (!empty($tags['WEB_DETECTION'][0]['webDetection']['visuallySimilarImages'])) {
        foreach ($tags['WEB_DETECTION'][0]['webDetection']['visuallySimilarImages'] as $tag) {
          $simple['WEB_DETECTION-SIMILAR-IMAGES'][] = [
            'tag' => !empty($tag['url']) ? $tag['url'] : NULL,
//            'score' => NULL,
            'link' => !empty($tag['url']) ? $tag['url'] : NULL
          ];
        }
      }

      if (!empty($tags['WEB_DETECTION'][0]['webDetection']['pagesWithMatchingImages'])) {
        foreach ($tags['WEB_DETECTION'][0]['webDetection']['pagesWithMatchingImages'] as $tag) {
          $simple['WEB_DETECTION-PAGES-WITH-MATCHING'][] = [
            'tag' => !empty($tag['url']) ? $tag['url'] : NULL,
            //            'score' => NULL,
            'link' => !empty($tag['url']) ? $tag['url'] : NULL
          ];
        }
      }

      $tags['simple'] = $simple;

      $cache->set($cache_key, $tags);
      $tags['cached'] = FALSE;

      $response = new JsonResponse($tags);
      $response->setEncodingOptions($response->getEncodingOptions() | JSON_PRETTY_PRINT);

      return $response;
    }

    private function getGoogleJson($image, $type) {
      return json_encode(
        ['requests' =>[
          'image' => [
            'content' => $image
          ],
          "features" => [
            "type" => $type,
            'maxResults' => 20
          ]
        ]
      ]);
    }


    /*** Helper Functions  ***/

    private function getImagePath($id) {
      $em = $this->get('doctrine')->getManager();
      $repository = $em->getRepository('AppBundle:Image');
      $product = $repository->find($id);
      $img_dir = $this->getParameter('images_directory');
      $image_path = $img_dir . '/' . $product->getImage();
      return $image_path;
    }



    private function getGuzzleClient() {
      static $client;
      if (!empty($client)) {
        return $client;
      }
      $client = new Client();
      return $client;
    }

  private function getCache() {
    static $cache;
    if (!empty($cache)) {
      return $cache;
    }
    $cache = new FilesystemCache();
    return $cache;
  }

  private function getCacheItem($cache_key) {
      if (!empty($_GET['reset'])) {
        return FALSE;
      }
      $cache = $this->getCache();
      if ($cache->has($cache_key)) {
        $tags = $cache->get($cache_key);
        $tags['cached'] = TRUE;
        $response = new JsonResponse($tags);
        $response->setEncodingOptions($response->getEncodingOptions() | JSON_PRETTY_PRINT);
        return $response;
      }
      return FALSE;
  }
}
