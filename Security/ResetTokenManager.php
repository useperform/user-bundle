<?php

namespace Perform\UserBundle\Security;

use Perform\UserBundle\Entity\User;
use Perform\UserBundle\Entity\ResetToken;
use Perform\NotificationBundle\Notifier\NotifierInterface;
use Perform\NotificationBundle\Notification;
use Doctrine\ORM\EntityManagerInterface;
use Perform\BaseBundle\Doctrine\EntityResolver;

/**
 * Handles the creation and validation of password reset tokens.
 *
 * @author Glynn Forrest <me@glynnforrest.com>
 **/
class ResetTokenManager
{
    protected $em;
    protected $entityResolver;
    protected $userManager;
    protected $notifier;
    protected $expirySeconds;

    public function __construct(EntityManagerInterface $em, UserManager $userManager, EntityResolver $entityResolver, NotifierInterface $notifier, $expirySeconds)
    {
        $this->em = $em;
        $this->entityResolver = $entityResolver;
        $this->userManager = $userManager;
        $this->expirySeconds = $expirySeconds;
        $this->notifier = $notifier;
    }

    public function createToken(User $user)
    {
        $token = new ResetToken();
        $token->setUser($user);
        $token->setExpiresAt(new \DateTime(sprintf('+%s seconds', $this->expirySeconds)));
        $token->setSecret(bin2hex(random_bytes(64)));

        return $token;
    }

    public function createAndSaveToken($email)
    {
        $user = $this->em->getRepository($this->entityResolver->resolve('PerformUserBundle:User'))
              ->findOneBy(['email' => $email]);
        if (!$user) {
            throw new ResetTokenException(sprintf('User "%s" not found.', $email));
        }

        $token = $this->createToken($user);
        $this->em->persist($token);
        $this->em->flush();

        return $token;
    }

    public function isTokenValid(ResetToken $token, $secret)
    {
        return $token->getExpiresAt() > new \DateTime() && $token->getSecret() === $secret;
    }

    public function findToken($id)
    {
        return $this->em->getRepository('PerformUserBundle:ResetToken')
            ->find($id);
    }

    public function findAndValidateToken($id, $secret)
    {
        $token = $this->findToken($id);
        if (!$token || !$this->isTokenValid($token, $secret)) {
            throw new ResetTokenException('Token not found or is invalid.');
        }

        return $token;
    }

    public function updatePassword(ResetToken $token, $newPassword)
    {
        $this->em->beginTransaction();
        try {
            $this->userManager->updatePassword($token->getUser(), $newPassword);
            $this->em->remove($token);
            $this->em->flush();
            $this->em->commit();
        } catch (\Exception $e) {
            $this->em->rollback();
            throw $e;
        }
    }

    public function sendNotification(ResetToken $token)
    {
        $notification = new Notification($token->getUser(), 'PerformUserBundle:reset_password', [
            'subject' => 'Reset your password',
            'token' => $token,
        ]);
        $this->notifier->send($notification, ['email']);
    }

    /**
     * @return int
     */
    public function removeStaleTokens(\DateTime $before)
    {
        return $this->em->createQuery('DELETE FROM PerformUserBundle:ResetToken t WHERE t.expiresAt < :before')
            ->setParameter('before', $before)
            ->execute();
    }
}
