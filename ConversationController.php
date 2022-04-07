<?php

namespace App\Controller;

use App\Entity\Attempt;
use App\Entity\AttemptConversation;
use App\Entity\ConversationMessage;
use App\Entity\Participant;
use App\Form\MessageFormType;
use App\Repository\AttemptConversationRepository;
use App\Repository\ConversationMessageRepository;
use App\Repository\MessageConfirmRepository;
use App\Service\MainHelper;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;

/**
 * @Route("/conversation")
 */
class ConversationController extends AbstractController
{
    /**
     * @var SessionInterface
     */
    private $session;
    /**
     * @var MainHelper
     */
    private $mh;
    /**
     * @var EntityManagerInterface
     */
    private $em;
    /**
     * @var AttemptConversationRepository
     */
    private $convRep;
    /**
     * @var ConversationMessageRepository
     */
    private $convMessageRep;
    /**
     * @var MessageConfirmRepository
     */
    private $messageConfirmRep;

    public function __construct(EntityManagerInterface $em,
                                RequestStack $requestStack,
                                MainHelper $mh,
                                AttemptConversationRepository $convRep,
                                ConversationMessageRepository $convMessageRep,
                                MessageConfirmRepository $messageConfirmRep)
                                //AttemptConversationRepository $attemptConvRep,
                                //MessageConfirmRepository $convMessageRep)
    {
        $this->em = $em;
        $this->session = $requestStack->getSession();
        $this->mh = $mh;
        //$this->convMessageRep = $convMessageRep;
        //$this->attemptConvRep = $attemptConvRep;
        $this->convRep = $convRep;
        $this->convMessageRep = $convMessageRep;
        $this->messageConfirmRep = $messageConfirmRep;
    }

    /**
     * @Route("/", name="conversation_list")
     */
    public function conversationList()
    {
        /** @var User $user */
        $user = $this->getUser();

        $this->session->set('currentListUrl', 'conversation_list');
        $back = $this->generateUrl('conversation_list');
        $this->session->set('linkBack', $back);

        //$messages = $this->convMessageRep->getUnReadUserMessages($user);
        //$this->convMessageRep->updateUnReadUserMessages($user);

        $mcs = $this->messageConfirmRep->getUnReadUserMessages($user);
        $this->messageConfirmRep->confirmMessages($user);

        if(!$mcs)
            $this->redirectToRoute('conversation_read_list');

        return $this->render('conversation/list.twig', [
            'mcs' => $mcs
        ]);
    }

    /**
     * @Route("/read", name="conversation_read_list")
     */
    public function conversationReadList(Request $request, PaginatorInterface $paginator) //Przeczytane
    {
        /** @var User $user */
        $user = $this->getUser();

        if($user->hasRole('ROLE_VERIFIER') OR $user->hasRole('ROLE_ANALYST'))
        {
            $response = $this->mh->goToNumberLab($request->query->get('attemptnumber'));
        }
        else
        {
            $response = $this->mh->goToNumberLabManager($request->query->get('attemptnumber'));

        }
        if ($response instanceof RedirectResponse)
            return $response;

        $this->session->set('currentListUrl', 'conversation_read_list');
        $back = $this->generateUrl('conversation_read_list');
        $this->session->set('linkBack', $back);

        $page =         $request->query->getInt('page', 1);

        $this->messageConfirmRep->confirmMessages($user);
        $messages = $this->convMessageRep->getReadUserMessages($user);
        $pagination = $paginator->paginate($messages, $page, 25);

        return $this->render('conversation/readlist.twig', [
            'messages' => $pagination
        ]);
    }

    /**
     * @Route("/attempt/{id}/new", name="new_conversation_attempt")
     * @param Attempt $attempt
     * @param Request $request
     * @return RedirectResponse|bool|\Symfony\Component\HttpFoundation\RedirectResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function newConversationAttempt(Attempt $attempt, Request $request)
    {
        if (!$attempt) {
            throw $this->createNotFoundException('Brak próby');
        }

        /** @var User $user */
        $user = $this->getUser();

        $back = $this->session->get('linkBack', null);

        $response = $this->mh->isGrantedMaterial($attempt->getMaterial());
        if ($response instanceof RedirectResponse)
            return $response;

        $urlList = $this->session->get('currentListUrl');

        $today = time();
        $conversation = new AttemptConversation();
        $conversation->setTimeCreated($today);
        $conversation->setCreateby($user);
        $conversation->setAttempt($attempt);
        $conversation->setAllowroles('TEAM');
        $title = 'Wprowadź treść wiadomości..';
        //Klient moze pisac tylko do Weryfikatora
        if($user->hasRole('ROLE_USEREBD') OR $user->hasRole('ROLE_CUSTOMER'))
        {
            $conversation->setAllowroles(['ROLE_VERIFIER']);
            $title = 'Wprowadź treść wiadomości dla Laboratorium..';
        }

        /** @var ConversationMessage $message */
        $message = new ConversationMessage();
        $message->setTimeCreated($today);
        $message->setCreateby($user);
        $message->setConversation($conversation);

        if($user->hasRole('ROLE_USEREBD') OR $user->hasRole('ROLE_CUSTOMER'))
            $message->setType('NONE');
        else if($user->hasRole('ROLE_VERIFIER'))
            $message->setType('CUSTOMER');
        else
            $message->setType('GROUP');

        $form = $this->createForm(MessageFormType::class, $message);


        // only handles data on POST
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {

            $message = $form->getData();

            //Grupy
            if(isset($form['_team']) && $form->get('_team')->getData())
            {
                $teams = $message->getTeam()->toArray();
                foreach($teams as $team) {
                    $message->getConversation()->addTeam($team);
                }
                //$team = $form->get('_team')->getData();
                //$message->getConversation()->setTeam($team);
                $message->getConversation()->getAttempt()->setLastMessage($message->getTimeCreated());
                $message->getConversation()->getLastMessage($message);
            }
            //Grupy Użytkownikow i Klientow
            if(isset($form['_user']) && $form->get('_user')->getData())
            {
                $cliteams = $message->getUser()->toArray();
                foreach($cliteams as $team) {
                    $message->getConversation()->addTeam($team);
                }
                //$team = $form->get('_user')->getData();
                //$message->getConversation()->setTeam($team);
                $message->getConversation()->getAttempt()->setLastMessage($message->getTimeCreated());
                $message->getConversation()->getLastMessage($message);

                /*$user = $form->get('_user')->getData();
                $message->setMessageto($user);
                $conversation->setAllowroles('USER');*/
            }

            $this->em->persist($message);
            $this->em->flush();

            $this->addFlash(
                'success',
                sprintf('Rozmowa została dodana przez: %s!', $this->getUser()->getFullName())
            );

            return $this->redirectToRoute('conversation_attempt', ['id' => $attempt->getId()]);
        }

        return $this->render('conversation/attempt/new.html.twig', [
            'attempt'       => $attempt,
            'material'      => $attempt->getMaterial(),
            'form'          => $form->createView(),
            'back'          => $back,
            'urlList'       => $urlList,
            'title' =>  $title
        ]);
    }

    /**
     * @Route("/attempt/{id}", name="conversation_attempt")
     */
    public function conversationAttempt(Attempt $attempt)
    {
        if (!$attempt) {
            throw $this->createNotFoundException('Brak próby');
        }

        /** @var User $user */
        $user = $this->getUser();

        //$conversations = $convMessageRepository->getUserMessages($attempt, $user);
        $conversations = $this->convRep->getUserConversations($attempt, $user);

        if(!$conversations)
        {
            return $this->redirectToRoute('new_conversation_attempt', ['id' => $attempt->getId()]);
        }

        $back = $this->session->get('linkBack', null);

        $response = $this->mh->isGrantedMaterial($attempt->getMaterial());
        if ($response instanceof RedirectResponse)
            return $response;

        $urlList = $this->session->get('currentListUrl');

        //$this->convMessageRep->updateUnReadUserMessages($user, $attempt);
        $this->messageConfirmRep->confirmAttemptMessages($user, $conversations);

        return $this->render('conversation/attempt/index.html.twig', [
            'attempt'       => $attempt,
            'material'      => $attempt->getMaterial(),
            'back'          => $back,
            'conversations' => $conversations,
            'urlList'       => $urlList,
            'user'          => $user
        ]);
    }
}
