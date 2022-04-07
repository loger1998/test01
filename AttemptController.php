<?php

namespace App\Controller;

use App\Entity\Attempt;
use App\Entity\AttemptComment;
use App\Entity\AttemptDetail;
use App\Entity\ComponentType;
use App\Entity\Department;
use App\Entity\User;
use App\Form\AttemptFormType;
use App\Form\AttemptAutoFormType;
use App\Form\AttemptViewFilterFormType;
use App\Form\AttemptYearFormType;
use App\Repository\AttemptCommentRepository;
use App\Repository\AttemptDetailRepository;
use App\Repository\AttemptRepository;
use App\Repository\ComponentRangeRepository;
use App\Repository\ComponentRepository;
use App\Repository\DepartmentRepository;
use App\Repository\ComponentTypeRepository;
use App\Repository\MaterialTemplateRepository;
use App\Service\MainHelper;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Component\Routing\Annotation\Route;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\HttpFoundation\Request;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use JMS\Serializer\SerializationContext;
use JMS\Serializer\SerializerInterface;


/**
 * @Route("/attempts")
 */
class AttemptController extends BaseController
{
    /**
     * @Route("/year/new", name="attempt_year_new")
     * @Security("is_granted('ROLE_ADD_ATTEMPT')")
     */
    public function newAttempt(Request $request, MainHelper $mh)
    {
        $request->getSession()->set('currentListUrl', 'attempt_year_list');

        $now = time();
        $year = $request->query->get('year',  $request->getSession()->get('year',  date("Y", $now)));

        $form = $this->createForm(AttemptYearFormType::class,
            [
                'year' => $year,
                'department' => null
            ],
            [
                'allow_extra_fields' => true,
                'csrf_protection' => false
            ]);

        // only handles data on POST
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            /** @var Department $department */
            $department = ($data['department']) ? $data['department'] : null;
            $material = ($data['material']) ? $data['material'] : null;
            $year = ($data['year']) ? $data['year'] : $year;

                $mh->setSessionDepartment($department->getDepartmentType(), $department);

            if($department->getAuto())
            {
                if($material)
                {
                    return $this->redirectToRoute('attempt_new',
                        ['id' => $material->getId(), 'year' => $year]);
                }
                else
                {
                    $provider = ($data['provider']) ? $data['provider'] : '';
                    $ps = explode(';', $provider);
                    return $this->redirectToRoute('attempt_auto_new',
                        ['id' => 1, 'provider' => $ps[0], 'substance' => $ps[1],'year' => $year]);
                }
            }
            else
            {
                return $this->redirectToRoute('attempt_new',
                    ['id' => $material->getId(), 'year' => $year]);
            }
        }

        return $this->render('attempt/year/new.html.twig', [
            'form' => $form->createView()
        ]);
    }

    /**
     * @Route("/year/{year}", defaults={"year": 0}, methods={"GET", "POST"}, name="attempt_year_list")
     * @Security("is_granted('ROLE_LIST_MATERIAL')")
     */
    public function yearList($year,
                             Request $request,
                             PaginatorInterface $paginator,
                             MainHelper $mh,
                             AttemptRepository $attemptRepository,
                             AttemptDetailRepository $attemptDetailRepository,
                             ComponentRangeRepository $componentRangeRepository)
    {
        $mh->resetSessionDepartment();

        $now = time();

        /** @var User $user */
        $user = $this->getUser();

        $page =         $request->query->getInt('page', 1);
        $sort =         $request->query->get('sort', 'attempt.dataReg');
        $direction =    $request->query->getAlpha('direction', 'asc');
        $year           = $year ? $year : $request->getSession()->get('year', date("Y", $now));
        //$month =        $request->query->get('month', $request->getSession()->get('month', date("m", $now)));

        $currentYear = date("Y", $now) + 1;
        $years = [];
        for ($i=2019; $i < $currentYear + 1; $i++){
            $years[] = $i;
        }


        $back = $this->generateUrl('attempt_year_list',
            ['year' => $year, 'page' => $page, 'sort' => $sort, 'direction' => $direction]);

        $request->getSession()->set('year', $year);
        $request->getSession()->set('linkBack', $back);
        $request->getSession()->set('currentListUrl', 'attempt_year_list');

        $search = $request->getSession()->get('filterAttemptView');
        unset($search['material']);
        $searchForm = $this->createForm(AttemptViewFilterFormType::class, $search);

        $detailColumns = $attemptDetailRepository
            ->findAllColumnViewYear($year, $user);

        $attempts = $attemptRepository
            ->getSearchMaterialAttemptViewYear($year, $sort, $direction, $user);

        $pagination = $paginator->paginate($attempts, $page, 25);

        $ranges = $componentRangeRepository->findBySearchResult();

        $language = [];
        $language[] = ['key' => 'pl', 'name' => 'Polski'];
        $language[] = ['key' => 'en', 'name' => 'Angielski'];

        $props = [
            'language' => $language,
            'context' => 'YearAttempts',
            'getAttemptsUrl' => $this->generateUrl('api_get_attempts'),
            'getAttemptExcelUrl' => $this->generateUrl('api_get_attempt_excel')
        ];

        return $this->render('attempt/year/list.html.twig', [
            'currentyear' => $year,
            'years' => $years,
            'detailColumns' => $detailColumns,
            'direction' => $direction,
            'pagination' => $pagination,
            'searchForm' => $searchForm->createView(),
            'ranges' => $ranges,
            'props' => $props,
        ]);
    }

    /**
     * @Route("/{id}/edit", methods={"GET", "POST"}, name="attempt_edit")
     * @Security("is_granted('ROLE_EDIT_ATTEMPT')")
     */
    public function editAction(Request $request,
                               Attempt $attempt,
                               EntityManagerInterface $em,
                               DepartmentRepository $departmentRepository,
                               ComponentRepository $componentRepository,
                               ComponentTypeRepository $componentTypeRepository,
                               MaterialTemplateRepository $materialTemplateRepository,
                               AttemptDetailRepository $detailRepository,
                               MainHelper $mh)
    {
        if (!$attempt) {
            throw $this->createNotFoundException('Brak próby');
        }

        /** @var User $user */
        $user = $this->getUser();

        $provider = $attempt->getProvider();
        $substance = $attempt->getSubstance();

        $copyas = $request->query->get('copyas') != null ? true : false;

        $response = $mh->isGrantedMaterial($attempt->getMaterial());
        if ($response instanceof RedirectResponse)
            return $response;

        $departmentId = $request->getSession()->get('department', 0) ?
            $request->getSession()->get('department', 0) : $request->getSession()->get('departmentClient', 0);

        /*Materiał może należeć tylko do jednego wydziału*/
        /** @var Department $department */
        $material = $attempt->getMaterial();
        $department = $departmentId ?
            $departmentRepository->find($departmentId) : $material->getDepartments() ? $material->getDepartments()[0] : null;

        $urlList = $request->getSession()->get('currentListUrl');

        $symbol = $attempt->getMaterial()->getSymbol() ?
            $attempt->getMaterial()->getSymbol() : $department->getSymbol();

        if($attempt->getMaterial()->isAuto()){
            if(!$attempt->getNumber()){
                $now = time();

                $attempt->setNmonth(date("n", $now));
                $attempt->setNsymbol($symbol);
                $attempt->setNyear(date("Y", $now));


                /** @var MaterialTemplate $defaultTemplate */
                //$defaultTemplate = $materialTemplateRepository->findDefault($attempt->getMaterial());
                $defaultTemplate = $materialTemplateRepository->findDefaultByProviderAndSubstance($provider, $substance);
                if($defaultTemplate){
                    $attempt->setTemplate($defaultTemplate);
                }
                $request->getSession()->set('newAttempt', $attempt);
            }
            $form = $this->createForm(AttemptAutoFormType::class, $attempt, [
                'askTemplate' => $attempt->isAskTemplate()
            ]);
        } else {
            $form = $this->createForm(AttemptFormType::class, $attempt,[
                'askTemplate' => $attempt->isAskTemplate(),
                'copyas' => $copyas
            ]);
            $form->get('symbol')->setData($symbol);
        }

        // only handles data on POST
        $form->handleRequest($request);

        if ($form->get('cancel')->isClicked()) {
            return $this->redirectToRoute($urlList, ['id' => $attempt->getMaterial()->getId()]);
        }

        if(!$attempt->getMaterial()->isAuto())
        {
            if ($form->get('saveAs')->isClicked()) {
                return $this->redirectToRoute('attempt_copyas', ['id' => $attempt->getId(), 'number' => $attempt->getNumber()]);
            }
        }

        if ($form->isSubmitted() && $attempt->getMaterial()->isAuto() && !$form->isValid())
        {
            $attempt = $form->getData();
            $attempt->setNumber($this->getNnumberLab($attempt));
            $attempt->setPosition($this->getPosition($attempt));
            foreach($attempt->getDetails() as $detail)
            {
                if($detail->getType() && $detail->getUnit() and !$detail->getTimeCreated()) {
                    $detail->setTimeCreated(time());
                }

                if(!$detail->getUser())
                    $detail->setUser($user);

                if($detail->getType() == null)
                {
                    $type = $detail->getComponent() ?
                        $detail->getComponent()->getType() : $componentTypeRepository->findNormalType();
                    $detail->setType($type);
                }
                if($detail->getComponent() && !$detail->getName()){
                    $detail->setName($detail->getComponent()->getName());
                }
            }
            $em->persist($attempt);
            $request->getSession()->set('newAttempt', $attempt);
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $attempt = $form->getData();
            $attempt->setNumber($this->getNnumberLab($attempt));
            $attempt->setPosition($this->getPosition($attempt));
            $attempt->setAskTemplate(true);
            $details = $attempt->getDetails();
            $attempt->setTimeModified(time());

            /** @var AttemptDetail $detail */
            $typNormal = $componentTypeRepository->findNormalType();
            $typStrainer = $componentTypeRepository->findStrainerType();
            $indexn = $detailRepository->findMaxPositionNormaType($attempt, $typNormal);
            $indexs = $detailRepository->findMaxPositionNormaType($attempt, $typStrainer);

            $this->splidAttemptNumber($attempt);

            foreach ($details as $detail) {

                /*if(!$detail->getComponent())
                {
                    $component = $componentRepository->findOneBy(['name' => $detail->getName()]);
                    if($component) {
                        $detail->setComponent($component);
                        $detail->setType($component->getType());
                    }
                }*/

                //do usuniecia
                $detail->setName($detail->getComponent()->getName());

                if(!$detail->getType())
                    $detail->setType($detail->getComponent()->getType());

                if(!$detail->getUser())
                    $detail->setUser($user);


                if ($detail->getNewValue() or $detail->getNewTemp()) {

                    if ($detail->getNewValue())
                        $detail->setValue(str_replace(',', '.', $detail->getNewValue()));

                    if ($detail->getNewTemp())
                        $detail->setTemp(str_replace(',', '.', $detail->getNewTemp()));

                    $detail->setDw(1);
                    $detail->setTimeModified(time());
                    if(!$detail->getTimeCreated()){
                        $detail->setTimeCreated(time());
                    }
                    $detail->setStatus(4);//zatwierdzone
                }

                if(!$detail->getTimeCreated()){
                    $detail->setTimeCreated(time());

                    $type = $detail->getType();

                    if($type->getId() == ComponentType::TYPE_NORMAL){

                        $indexn++;
                        $detail->setPosition($indexn);
                    } else {
                        $indexs++;
                        $detail->setPosition($indexs);
                    }
                }
                //$detail->setToOrder(false);
                $detail->setNewValue(null);
            }
            $attempt->setNumber($this->getNnumberLab($attempt));
            $attempt->setPosition($this->getPosition($attempt));
            $this->setTypeAttempt($attempt);

            $em->persist($attempt);
            $em->flush();

            $this->addFlash(
                'success',
                sprintf('Dane próby zostały zapisane przez: %s.', $this->getUser()->getFullName())
            );

            return $this->redirectToRoute($urlList, ['id' => $attempt->getMaterial()->getId()]);
        }

        return $this->render('attempt/edit.html.twig', [
            'attemptForm' => $form->createView(),
            'material' => $attempt->getMaterial(),
            'provider' => $provider,
            'substance' => $substance,
            'attempt' => $attempt,
            'attemptStatus' => $attempt->getStatus(),
            'urlList' => $urlList,
            'copyas' => $copyas
        ]);
    }

    /**
     * @Route("/{id}/copyas", name="attempt_copyas")
     * @Security("is_granted('ROLE_EDIT_ATTEMPT')")
     */
    public function copyasAction(Request $request,
                                 Attempt $attempt,
                                 EntityManagerInterface $em,
                                 DepartmentRepository $departmentRepository,
                                 MainHelper $mh)
    {
        if (!$attempt) {
            throw $this->createNotFoundException('Brak próby');
        }

        $response = $mh->isGrantedMaterial($attempt->getMaterial());
        if ($response instanceof RedirectResponse)
            return $response;

        $urlList = $request->getSession()->get('currentListUrl');
        $numberAttempt =  $request->query->get('number', '');

        $details = $attempt->getDetails();

        $departmentId = $request->getSession()->get('department', 0) ?
            $request->getSession()->get('department', 0) : $request->getSession()->get('departmentClient', 0);

        /** @var Department $department */
        $department = $departmentRepository->find($departmentId);

        $now = time();
        $year = date("Y", $now);
        $month = date("n", $now);
        //$symbol = $department->getSymbol();
        $symbol = $attempt->getMaterial()->getSymbol() ?
            $attempt->getMaterial()->getSymbol() : $department->getSymbol();

        /** @var User $user */
        $user = $this->getUser();

        $newAttempt = new Attempt();
        $newAttempt->setTimeCreated(time());
        $newAttempt->setTimeModified(0);
        $newAttempt->setDeleted(0);
        $newAttempt->setHidden(0);
        $newAttempt->resetStatus();
        $newAttempt->setNmonth($attempt->getNmonth());
        $newAttempt->setNyear($attempt->getNyear());
        $newAttempt->setNsymbol($attempt->getNsymbol());
        $newAttempt->setAskTemplate(true);
        $newAttempt->setMaterial($attempt->getMaterial());
        $newAttempt->setDepartment($attempt->getDepartment());
        $newAttempt->setName($attempt->getName());
        $newAttempt->setNumber('/'.$month.'/'.$symbol.'/'.$year);
        if($attempt->getNameAttempt())
            $newAttempt->setNameAttempt($attempt->getNameAttempt());
        if($attempt->getNumberCook())
            $newAttempt->setNumberCook($attempt->getNumberCook());
        if($attempt->getNumberSpec())
            $newAttempt->setNumberSpec($attempt->getNumberSpec());
        if($attempt->getNumberRej())
            $newAttempt->setNumberRej($attempt->getNumberRej());

        //$newAttempt->setDataReg($attempt->getDataReg());
        $dataReg = new \DateTime();
        if($attempt->getMaterial()->getDelayofdays())
            $dataReg->modify('-'.$attempt->getMaterial()->getDelayofdays().' day');
        $attempt->setDataReg($dataReg);

        $newAttempt->setLp($attempt->getLp());
        $newAttempt->setType($attempt->getType());
        $newAttempt->setTypeDecade($attempt->getTypeDecade());
        $newAttempt->setTypeMonth($attempt->getTypeMonth());
        $newAttempt->setTypeYear($attempt->getTypeYear());
        $attempt->isPreference() ? $newAttempt->setPreference(true) : $newAttempt->setPreference(false);

        //jeżeli dodane w laboratorium to nie ma dostawcy
        if(!$attempt->getDelivery()){
            $newAttempt->setUser($this->getUser());
        } else {
            $newAttempt->setDelivery($attempt->getDelivery());
        }
        $newAttempt->setProvider($attempt->getProvider());
        $newAttempt->setSubstance($attempt->getSubstance());

        /** @var AttemptDetail $detail */

        foreach ($details as $detail)
        {
            $detailNew = new AttemptDetail();
            $detailNew->setTimeCreated(time());
            $detailNew->setTimeModified(0);
            $detailNew->setDeleted(0);
            $detailNew->setStatus(0);
            $detailNew->setValue(null);
            $detailNew->setUnit($detail->getUnit());
            $detailNew->setName($detail->getName());
            $detailNew->setComponent($detail->getComponent());
            $detailNew->setType($detail->getType());
            $detailNew->setUser($user);

            $detailNew->setPosition($detail->getPosition());
            if ($detail->isToOrder())
            {
                ($detail->isDw()) ? $detailNew->setDw(0) : $detailNew->setDw(1);
            }
            else if($detail->getValue() or $detail->isDw())
            {
                $detailNew->setDw(1);
            }

            $newAttempt->addDetail($detailNew);
        }

        $em->persist($newAttempt);
        $em->flush();

        $this->addFlash(
            'success',
            sprintf('Dane próby zostały zapisane przez: %s.', $this->getUser()->getFullName())
        );

        //return $this->redirectToRoute('attempt_edit', ['id' => $newAttempt->getId()]); kopije tez oryginal
        //return $this->redirectToRoute($urlList, ['id' => $attempt->getMaterial()->getId()]);
        return $this->redirectToRoute('attempt_edit', ['id' => $newAttempt->getId(), 'copyas' => true]);
    }

    /**
     * @Route("/{id}/show", name="attempt_show")
     * @Security("is_granted('ROLE_SHOW_ATTEMPT')")
     */
    public function showAction(Attempt $attempt, MainHelper $mh)
    {
        if (!$attempt) {
            throw $this->createNotFoundException('Brak próby');
        }

        $response = $mh->isGrantedMaterial($attempt->getMaterial());
        if ($response instanceof RedirectResponse)
            return $response;

        return $this->render('attempt/show.html.twig', [
            'attempt' => $attempt,
            'material' => $attempt->getMaterial()
        ]);
    }

    /**
     * @Route("/{id}/delete", name="attempt_delete")
     * @Security("is_granted('ROLE_MANAGER')")
     */
    public function deleteAction(Attempt $attempt,
                                 Request $request,
                                 EntityManagerInterface $em,
                                 LoggerInterface $databaseLogger,
                                 SerializerInterface $serializer,
                                 MainHelper $mh)
    {
        if (!$attempt) {
            throw $this->createNotFoundException('Brak próby');
        }

        $response = $mh->isGrantedMaterial($attempt->getMaterial());
        if ($response instanceof RedirectResponse)
            return $response;

        $urlList = $request->getSession()->get('currentListUrl');

        $json = $serializer->serialize($attempt, 'json', SerializationContext::create()->setGroups(array('log')));
        $materialId = $attempt->getMaterial()->getId();

        $em->remove($attempt);
        $em->flush();

        $databaseLogger->info('Usunięto', [
            'fields' => [
                'changes' => $json,
                'action' => 'delete',
                'entityClass' => 'Attempt',
                'entity' => null
            ]
        ]);

        $this->addFlash(
            'success',
            sprintf('Próba została usunięta przez: %s.', $this->getUser()->getFullName())
        );

        return $this->redirectToRoute($urlList, ['id' => $materialId]);
    }

    /**
     * @Route("/{id}/comments/new", name="comment_new")
     * @Security("is_granted('ROLE_USER')")
     */
    public function newCommentAction(Request $request,
                                     Attempt $attempt,
                                     EntityManagerInterface $em,
                                     MainHelper $mh,
                                     AttemptCommentRepository $attemptCommentRep)
    {
        if (!$attempt) {
            throw new NotFoundHttpException('Brak próby');
        }

        $back = $request->getSession()->get('linkBack', null);

        $response = $mh->isGrantedMaterial($attempt->getMaterial());
        if ($response instanceof RedirectResponse)
            return $response;

        $urlList = $request->getSession()->get('currentListUrl');

        $newAttemptComment = new AttemptComment();
        $newAttemptComment->setUser($this->getUser());
        $newAttemptComment->setAttempt($attempt);

        $form = $this->createFormBuilder($newAttemptComment)
            ->add('comment', TextareaType::class, [
                'label' => false
            ])
            ->add('save',  SubmitType::class)
            ->add('cancel', SubmitType::class, array(
                // disable validation
                'validation_groups' => false,
            ))
            ->getForm();

        $form->handleRequest($request);

        if ($form->get('cancel')->isClicked()) {
            if($urlList) {
                return $this->redirectToRoute($urlList, ['id' => $attempt->getMaterial()->getId()]);
            } else {
                return $this->redirectToRoute('homepage');
            }
        }

        if ($form->isSubmitted() && $form->isValid()) {
            $comment = $form->getData();
            $comment->setTimeCreated(time());
            $comment->setTimeModified(0);
            $comment->setDeleted(0);
            $comment->setUser($this->getUser());
            $comment->setAttempt($attempt);

            $em->persist($comment);
            $em->flush();

            $this->addFlash(
                'success',
                sprintf('Komentarz został dodany przez: %s!', $this->getUser()->getFullName())
            );

            return $this->redirectToRoute('comment_new', ['id' => $attempt->getId()]);
        }

        $comments = $attemptCommentRep->getComments($attempt);

        return $this->render('comment/new.html.twig', [
            'back' => $back,
            'form' => $form->createView(),
            'comments' => $comments,
            'attempt' => $attempt,
            'material' => $attempt->getMaterial(),
            'urlList' => $urlList
        ]);
    }
}
