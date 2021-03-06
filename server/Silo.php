<?php

namespace Silo;

use Silex\Application;
use Silo\Base\ConfigurationProvider;
use Silo\Base\ConstraintValidatorFactory;
use Silo\Base\Provider\DoctrineProvider;
use Silo\Base\Provider\IndexProvider;
use Silo\Base\Provider\MetricProvider;
use Silo\Base\ValidationException;
use Silo\Inventory\BatchCollectionFactory;
use Silo\Inventory\GC\GarbageCollectorProvider;
use Silo\Inventory\Model\Location;
use Silo\Inventory\Model\Operation;
use Silo\Inventory\OperationValidator;
use Silo\Inventory\Playbacker;
use Symfony\Component\Debug\ErrorHandler;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\Validation;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
/**
 * Main Silo entry point, exposed as a Container.
 */
class Silo extends \Silex\Application
{
    /**
     * {@inheritdoc}
     */
    public function __construct(array $values = [])
    {
        // @todo Should be a check
        // if (!ini_get('date.timezone')) {
        //    ini_set('date.timezone', 'UTC');
        //}

        parent::__construct($values);

        $this->register(new ConfigurationProvider);
        $this['config']
            ->has('configured', false) // @todo should be false in a not so distant future
            ->has('route.base', '/silo/inventory')
            ->has('em.dsn', null);

        //$this['config']->save();

        $this['debug'] = true;
        if ($this['configured']) {
            $this->register(new MetricProvider);
            $this->register(new DoctrineProvider);
        }

        if (class_exists('\\Sorien\\Provider\\PimpleDumpProvider')) {
            //$app->register(new \Sorien\Provider\PimpleDumpProvider());
        }
        $app = $this;
        $this['location.provider'] = $app->protect(function($notDeleted = true)use($app){
            return function ($code) use ($app, $notDeleted) {

                $location = $app['em']->getRepository(Location::class)->findOneByCode($code);
                if (!$location || ($location->isDeleted() && $notDeleted)) {
                    throw new NotFoundHttpException("Location $code cannot be found");
                }

                return $location;
            };
        });

        $this['operation.provider'] = function($app){
            return function ($id) use ($app) {
                $operation = $app['em']->getRepository(Operation::class)->find($id);
                if (!$operation) {
                    throw new NotFoundHttpException("Operation $id cannot be found");
                }
                return $operation;
            };
        };

        $app = $this;

        $app['validator'] = function () use ($app) {
            return Validation::createValidatorBuilder()
                ->addMethodMapping('loadValidatorMetadata')
                ->setConstraintValidatorFactory(new ConstraintValidatorFactory($app))
                ->getValidator();
        };

        $this->register(new GarbageCollectorProvider);

        if (!$app->offsetExists('OperationValidator')) {
            $app['OperationValidator'] = function () use ($app) {
                return new OperationValidator();
            };
        }

        $app['BatchCollectionFactory'] = function () use ($app) {
            return new BatchCollectionFactory(
                $app['em'],
                $app['validator'],
                isset($app['SkuTransformer']) ? $app['SkuTransformer'] : null
            );
        };

        $app['Playbacker'] = function () use ($app) {
            $s = new Playbacker();
            $s->setEntityManager($app['em']);
            return $s;
        };

        $app->mount($app['route.base'].'/location', new \Silo\Inventory\LocationController);
        $app->mount($app['route.base'].'/operation', new \Silo\Inventory\OperationController);
        $app->mount($app['route.base'].'/product', new \Silo\Inventory\ProductController);
        $app->mount($app['route.base'].'/batch', new \Silo\Inventory\BatchController);
        $app->mount($app['route.base'].'/user', new \Silo\Inventory\UserController);
        $app->mount($app['route.base'].'/export', new \Silo\Inventory\ExportController);

        $app['version'] = function(){
            $filename = __DIR__.'/../../VERSION';
            if (file_exists($filename) && is_readable($filename))  {
                $data = file_get_contents($filename);
                foreach(explode("\n", $data) as $line) {
                    if (preg_match('/^([^=]+)=(.*)/', $line, $matches)) {
                        if ($matches[1] == 'release') {
                            return $matches[2];
                        }
                    }
                }
            }
            return null;
        };

        // Deal with exceptions
        ErrorHandler::register();
        if (isset($app['defaultErrorHandler']) && $app['defaultErrorHandler']) {
            $app->error(function (\Exception $e, $request) use ($app) {
                if ($e instanceof NotFoundHttpException) {
                    $subRequest = Request::create('/');
                    return $app->handle($subRequest, HttpKernelInterface::SUB_REQUEST, false);
                }
                if ($e instanceof ValidationException) {
                    return new JsonResponse(['errors' => array_map(function ($violation) {
                        return (string) $violation;
                    }, iterator_to_array($e->getViolations()->getIterator()))], JsonResponse::HTTP_BAD_REQUEST);
                }

                if ($app['logger']) {
                    $app['logger']->error($e);
                }
                return new JsonResponse([
                    'message' => $e->getMessage(),
                    'trace' => $e->getTrace(),
                    'file' => $e->getFile().':'.$e->getLine()
                ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
            });
        }
    }
}
