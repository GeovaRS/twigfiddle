<?php

/*
 * This file is part of twigfiddle.com project.
 *
 * (c) Alain Tiemblo <alain@fuz.org>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Fuz\AppBundle\Controller;

use Fuz\AppBundle\Base\BaseController;
use Fuz\AppBundle\Entity\Fiddle;
use Fuz\AppBundle\Entity\UserBookmark;
use Fuz\AppBundle\Form\FiddleType;
use Fuz\AppBundle\Form\UserBookmarkType;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class FiddleController extends BaseController
{
    /**
     * Runs a fiddle.
     *
     * @Route(
     *      "/run",
     *      name = "run_fiddle"
     * )
     * @Method({"POST"})
     */
    public function runAction(Request $request)
    {
        return $this->validateAjaxFiddle($request, new Fiddle(),
              function (Fiddle $fiddle) use ($request) {
                  $response = [];

                  if (!$this->get('app.captcha')->check($request, 'run')) {
                      $response['captcha'] = true;

                      return $response;
                  }

                  $result = $this->get('app.run_fiddle')->run($fiddle);

                  $response['result'] = $this
                  ->get('templating')
                  ->render('FuzAppBundle:Fiddle:result.html.twig', [
                      'fiddle' => $fiddle,
                      'data'   => $result,
                  ])
               ;

                  if ($result->getResult() && $result->getResult()->getContext()) {
                      $response['context'] = $this
                      ->get('templating')
                      ->render('FuzAppBundle:Fiddle:result-context.html.twig', [
                          'data' => $result,
                      ])
                   ;
                  }

                  return $response;
              });
    }

    /**
     * Saves a fiddle.
     *
     * fiddle.hash_regexp
     *
     * @Route(
     *      "/save/{revision}/{hash}",
     *      name = "save_fiddle",
     *      requirements = {
     *          "hash" = "^[a-zA-Z0-9-]{1,128}$",
     *          "revision" = "^\d+$"
     *      },
     *      defaults = {
     *          "hash" = null,
     *          "revision" = 0
     *      }
     * )
     * @Method({"POST"})
     */
    public function saveAction(Request $request, $hash, $revision)
    {
        $fiddle = $this->getFiddle($hash, $revision);

        if ($revision > 0) {
            $bookmark = $this->getUserBookmark($fiddle);
            if ($bookmark) {
                return $this->saveUserBookmark($request, $bookmark);
            }
        }

        return $this->validateAjaxFiddle($request, $fiddle,
              function (Fiddle $fiddle) use ($request, $hash, $revision) {
                  $response = [];

                  $user = $this->getUser();
                  $saveService = $this->get('app.save_fiddle');

                  if (is_null($fiddle->getId()) || !$saveService->ownsFiddle($fiddle, $user)) {
                      $revision = 0;
                  }

                  if (!$revision && !$this->get('app.captcha')->check($request, 'save')) {
                      $response['captcha'] = true;

                      return $response;
                  }

                  if ($fiddle->getId()) {
                      $hash = $fiddle->getHash();
                  }

                  if (!$saveService->validateHash($hash)) {
                      $hash = null;
                  }

                  $originalId = $fiddle->getId();

                  $saved = $saveService->save($hash, $revision, $fiddle, $user);
                  $saveService->saveFiddleToSession($saved->getId(), $user);

                  if ($originalId !== $saved->getId()) {
                      $response['relocate'] = $this->generateUrl('fiddle',
                      ['hash' => $saved->getHash(), 'revision' => $saved->getRevision()]);

                      return $response;
                  }
              });
    }

    /**
     * Bookmark / Unbookmark a fiddle.
     *
     * fiddle.hash_regexp
     *
     * @Route(
     *      "/bookmark/{revision}/{hash}",
     *      name = "bookmark_fiddle",
     *      requirements = {
     *          "hash" = "^[a-zA-Z0-9-]{1,128}$",
     *          "revision" = "^\d+$"
     *      }
     * )
     * @Method({"POST"})
     */
    public function bookmarkAction(Request $request, $hash, $revision)
    {
        $response = [
                'isBookmarked' => false,
        ];

        $user = $this->getUser();

        if ((!$request->isXmlHttpRequest()) || (is_null($user))) {
            return new RedirectResponse($this->generateUrl('fiddle', $response, Response::HTTP_PRECONDITION_REQUIRED));
        }

        $fiddle = $this->getFiddle($hash, $revision);

        if ($this->get('app.save_fiddle')->ownsFiddle($fiddle, $user)) {
            return new JsonResponse($response);
        }

        $bookmarkRepo = $this
           ->getDoctrine()
           ->getRepository('FuzAppBundle:UserBookmark')
        ;

        $em = $this->getDoctrine()->getManager();

        $old = $bookmarkRepo->getBookmark($fiddle, $user);
        if ($old) {
            $em->remove($old);
            $em->flush($old);

            return new JsonResponse($response);
        }

        $new = new UserBookmark();
        $new->setUser($user);
        $new->setFiddle($fiddle);

        return $this->saveUserBookmark($request, $new);
    }

    /**
     * Fiddle's samples.
     *
     * @Template()
     */
    public function samplesAction()
    {
        $webConfig = $this->container->getParameter('web');

        return [
                'categories' => $webConfig['samples'],
        ];
    }

    /**
     * Displays twigfiddle's editor.
     *
     * fiddle.hash_regexp
     *
     * @Route(
     *      "/{hash}/{revision}",
     *      name = "fiddle",
     *      requirements = {
     *          "hash" = "^[a-zA-Z0-9-]{1,128}$",
     *          "revision" = "^\d+$"
     *      },
     *      defaults = {
     *          "hash" = null,
     *          "revision" = 1
     *      }
     * )
     * @Method({"GET"})
     * @Template()
     */
    public function indexAction($hash, $revision)
    {
        $fiddleRepo = $this
           ->getDoctrine()
           ->getRepository('FuzAppBundle:Fiddle')
        ;

        $user   = $this->getUser();
        $fiddle = $this->getFiddle($hash, $revision);
        $fiddleRepo->incrementVisitCount($fiddle);

        $bookmark = $this->getUserBookmark($fiddle);
        if ($bookmark) {
            $fiddle->mapBookmark($bookmark);
        }

        $form = $this->createForm(FiddleType::class, $fiddle);

        $processConfig = $this->get('app.process_configuration')->getProcessConfig();

        return [
                'form'            => $form->createView(),
                'data'            => $fiddle,
                'hash'            => $hash,
                'revision'        => $revision,
                'canSave'         => $this->get('app.save_fiddle')->canSaveFiddle($fiddle, $user),
                'revisionBrowser' => $fiddleRepo->getRevisionList($fiddle, $user),
                'bookmark'        => $bookmark,
                'twigEngines'     => json_encode($processConfig['supported_versions']),
        ];
    }

    protected function saveUserBookmark(Request $request, UserBookmark $bookmark)
    {
        $response = [
                'isBookmarked' => false,
        ];

        $form = $this->createForm(UserBookmarkType::class, $bookmark,
           [
                'data_object' => $bookmark,
        ]);

        $form->handleRequest($request);

        if (!$form->isValid()) {
            $response['errors'] = $this->getErrorMessagesAjaxFormat($form);

            return new JsonResponse($response);
        }

        $em = $this->getDoctrine()->getManager();
        $em->persist($bookmark);
        $em->flush($bookmark);

        $response['isBookmarked'] = true;

        return new JsonResponse($response);
    }

    /**
     * Performs a callback if Fiddle's form is properly submitted and valid.
     *
     * @param Request     $request
     * @param Fiddle|null $fiddle
     * @param callable    $onValid
     *
     * @return RedirectResponse|JsonResponse
     */
    protected function validateAjaxFiddle(Request $request, $fiddle, $onValid)
    {
        $response = [];

        if (!$request->isXmlHttpRequest()) {
            return new RedirectResponse($this->generateUrl('fiddle', $response, Response::HTTP_PRECONDITION_REQUIRED));
        }

        $form = $this->createForm(FiddleType::class, $fiddle, [
                'data_object' => $fiddle,
        ]);
        $form->handleRequest($request);

        if ($form->isValid() || true) {
            $result = $onValid($fiddle);
            if (is_array($result)) {
                $response = array_merge($response, $result);
            }
        } else {
            $errors = $this->getErrorMessagesAjaxFormat($form);
            if (!array_key_exists('#', $errors)) {
                $plurial     = count($errors) > 1 ? 's' : '';
                $errors['#'] = [
                        "Form contains error{$plurial}, please check messages below involved field{$plurial}.",
                ];
            }
            $response['errors'] = $errors;
        }

        return new JsonResponse($response);
    }
}
