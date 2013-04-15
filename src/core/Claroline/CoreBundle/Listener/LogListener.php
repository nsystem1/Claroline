<?php

namespace Claroline\CoreBundle\Listener;

use Symfony\Component\DependencyInjection\ContainerAware;
use Claroline\CoreBundle\Library\Event\LogGenericEvent;
use Claroline\CoreBundle\Library\Event\LogGroupDeleteEvent;
use Claroline\CoreBundle\Library\Event\LogResourceDeleteEvent;
use Claroline\CoreBundle\Library\Event\LogUserDeleteEvent;
use Claroline\CoreBundle\Library\Event\LogWorkspaceRoleDeleteEvent;
use Claroline\CoreBundle\Library\Event\NotRepeatableLog;
use Claroline\CoreBundle\Entity\Logger\Log;

class LogListener extends ContainerAware
{
    private function createLog(LogGenericEvent $event)
    {
        $em = $this->container->get('doctrine.orm.entity_manager');
        $em->flush();

        $logger = $this->container->get('logger');
        $logger->info('onLogResource');

        //Add doer details
        $token = $this->container->get('security.context')->getToken();
        $doer = null;
        $sessionId = null;
        $doerIp = null;
        $doerType = null;

        if ($token === null) {
            $doer = null;
            $doerType = Log::doerTypePlatform;
        } else {
            if ($token->getUser() === 'anon.') {
                $doer = null;
                $doerType = Log::doerTypeAnonymous;
            } else {
                $doer = $token->getUser();
                $doerType = Log::doerTypeUser;
            }
            $request = $this->container->get('request');
            $sessionId = $request->getSession()->getId();
            $doerIp = $request->getClientIp();
        }

        $log = new Log();

        //Simple type properties
        $log->setAction($event->getAction());
        $log->setChildType($event->getChildType());
        $log->setChildAction($event->getChildAction());
        $log->setToolName($event->getToolName());

        //Object properties
        $log->setOwner($event->getOwner());
        if (!($event->getAction() === LogUserDeleteEvent::action && $event->getReceiver() === $doer)) {
            //Prevent self delete case
            $log->setDoer($doer);
        }
        $log->setDoerType($doerType);

        $log->setDoerIp($doerIp);
        if ($event->getAction() !== LogUserDeleteEvent::action) {
            //Prevent user delete case
            $log->setReceiver($event->getReceiver());
        }
        if ($event->getAction() !== LogGroupDeleteEvent::action) {
            $log->setReceiverGroup($event->getReceiverGroup());
        }
        if (!($event->getAction() === LogResourceDeleteEvent::action && $event->getResource() === $event->getWorkspace())) {
            //Prevent delete workspace case
            $log->setWorkspace($event->getWorkspace());
        }
        if ($event->getAction() !== LogResourceDeleteEvent::action) {
            //Prevent delete resource case
            $log->setResource($event->getResource());
        }
        if ($event->getAction() !== LogWorkspaceRoleDeleteEvent::action) {
            //Prevent delete role case
            $log->setRole($event->getRole());
        }

        if ($doer !== null) {
            $log->addDoerPlatformRole($doer->getPlatformRole());
            if ($event->getWorkspace() !== null) {
                $roleRepository = $em->getRepository('ClarolineCoreBundle:Role');
                $log->addDoerWorkspaceRole($roleRepository->findWorkspaceRoleForUser($doer, $event->getWorkspace()));
            }
        }
        if ($event->getResource() !== null) {
            $log->setResourceType($event->getResource()->getResourceType());
        }

        //Json_array properties
        $details = $event->getDetails();
        if ($details === null) {
            $details = array();
        }

        if ($doer !== null) {
            $details['doer'] = array(
                'first_name' => $doer->getFirstName(),
                'last_name' => $doer->getLastName(),
                'session_id' => $sessionId
            );

            if (count($log->getDoerPlatformRoles()) > 0) {
                $doerPlatformRolesDetails = array();
                foreach ($log->getDoerPlatformRoles() as $platformRole) {
                    $doerPlatformRolesDetails[] = $platformRole->getTranslationKey();   
                }
                $details['doer']['platform_roles'] = $doerPlatformRolesDetails;
            }
            if (count($log->getDoerWorkspaceRoles()) > 0) {
                $doerWorkspaceRolesDetails = array();
                foreach ($log->getDoerWorkspaceRoles() as $workspaceRole) {
                    $doerWorkspaceRolesDetails[] = $workspaceRole->getTranslationKey();   
                }
                $details['doer']['workspace_roles'] = $doerWorkspaceRolesDetails;
            }
        }
        $log->setDetails($details);

        $em->persist($log);
        $em->flush();
    }

    /**
     * Is a repeat if the session contains a same logSignature for the same action category
     * TODO add a time range concept date params in the session object
     */
    private function isARepeat(LogGenericEvent $event)
    {   
        if ($this->container->get('security.context')->getToken() === null) {
            //Only if have a user session;

            return false;
        }

        if ($event instanceof NotRepeatableLog) {
            $request = $this->container->get('request');
            $session = $request->getSession();

            $is = false;
            $pushInSession = true;
            $now = time();
            if ($session->get($event->getAction()) != null) {
                $oldArray = json_decode($session->get($event->getAction()));
                $oldSignature = $oldArray->logSignature;
                $oldTime = $oldArray->time;

                if ($oldSignature == $event->getLogSignature()) {
                    $diff = ($now - $oldTime);
                    if ($diff > $this->container->getParameter('non_repeatable_log_time_in_seconds')) {
                        $is = false;
                    } else {
                        $is = true;
                        $pushInSession = false;
                    }
                }                
            }

            if ($pushInSession) {
                //Update last logSignature for this event category
                $array = array('logSignature' => $event->getLogSignature(), 'time' => $now);
                $session->set($event->getAction(), json_encode($array));
            }

            return $is;
        } else {

            return false;
        }
    }

    public function onLog(LogGenericEvent $event)
    {
        if (!($event instanceof NotRepeatableLog) or !$this->isARepeat($event)) {
            $this->createLog($event);
        }
    }
}