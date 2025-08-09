<?php

namespace Miraheze\RequestSSL;

use MediaWiki\Config\Config;
use MediaWiki\Context\IContextSource;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Linker\Linker;
use MediaWiki\MainConfigNames;
use MediaWiki\Message\Message;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\User\User;
use MediaWiki\WikiMap\WikiMap;
use OOUI\HtmlSnippet;
use OOUI\MessageWidget;
use UserNotLoggedIn;

class RequestSSLViewer {

	public function __construct(
		private readonly Config $config,
		private readonly IContextSource $context,
		private readonly PermissionManager $permissionManager,
		private readonly RequestSSLManager $requestManager
	) {
	}

	/**
	 * @return array
	 */
	public function getFormDescriptor(): array {
		if ( $this->requestManager->isLocked() ) {
			$this->context->getOutput()->addHTML(
				Html::warningBox(
					Html::element(
						'p',
						[],
						$this->context->msg( 'requestssl-request-locked' )->text()
					),
					'mw-notify-error'
				)
			);
		}

		$this->context->getOutput()->enableOOUI();

		$formDescriptor = [
			'customdomain' => [
				'label-message' => 'requestssl-label-customdomain',
				'type' => 'url',
				'readonly' => true,
				'section' => 'details',
				'default' => $this->requestManager->getCustomDomain(),
			],
			'target' => [
				'label-message' => 'requestssl-label-target',
				'type' => 'text',
				'readonly' => true,
				'section' => 'details',
				'default' => $this->requestManager->getTarget(),
			],
			'requester' => [
				'label-message' => 'requestssl-label-requester',
				'type' => 'info',
				'section' => 'details',
				'default' => htmlspecialchars( $this->requestManager->getRequester()->getName() ) .
					Linker::userToolLinks(
						$this->requestManager->getRequester()->getId(),
						$this->requestManager->getRequester()->getName()
					),
				'raw' => true,
			],
			'requestedDate' => [
				'label-message' => 'requestssl-label-requested-date',
				'type' => 'info',
				'section' => 'details',
				'default' => $this->context->getLanguage()->timeanddate(
					$this->requestManager->getTimestamp(), true
				),
			],
			'status' => [
				'label-message' => 'requestssl-label-status',
				'type' => 'text',
				'readonly' => true,
				'section' => 'details',
				'default' => $this->context->msg(
					'requestssl-label-' . $this->requestManager->getStatus()
				)->text(),
			],
			'reason' => [
				'type' => 'textarea',
				'rows' => 4,
				'readonly' => true,
				'label-message' => 'requestssl-label-reason',
				'default' => $this->requestManager->getReason(),
				'raw' => true,
				'cssclass' => 'requestssl-infuse',
				'section' => 'details',
			],
		];

		foreach ( $this->requestManager->getComments() as $comment ) {
			$formDescriptor['comment' . $comment['timestamp'] ] = [
				'type' => 'textarea',
				'readonly' => true,
				'section' => 'comments',
				'rows' => 4,
				'label-message' => [
					'requestssl-header-comment-withtimestamp',
					$comment['user']->getName(),
					$this->context->getLanguage()->timeanddate( $comment['timestamp'], true ),
				],
				'default' => $comment['comment'],
			];
		}

		$user = $this->context->getUser();

		if (
			$this->permissionManager->userHasRight( $user, 'handle-custom-domain-requests' ) ||
			$user->getActorId() === $this->requestManager->getRequester()->getActorId()
		) {
			$formDescriptor += [
				'comment' => [
					'type' => 'textarea',
					'rows' => 4,
					'label-message' => 'requestssl-label-comment',
					'section' => 'comments',
					'validation-callback' => [ $this, 'isValidComment' ],
					'disabled' => $this->requestManager->isLocked(),
				],
				'submit-comment' => [
					'type' => 'submit',
					'default' => $this->context->msg( 'requestssl-label-add-comment' )->text(),
					'section' => 'comments',
					'disabled' => $this->requestManager->isLocked(),
				],
				'edit-source' => [
					'label-message' => 'requestssl-label-customdomain',
					'type' => 'url',
					'section' => 'editing',
					'required' => true,
					'default' => $this->requestManager->getCustomDomain(),
					'disabled' => $this->requestManager->isLocked(),
				],
				'edit-target' => [
					'label-message' => 'requestssl-label-target',
					'type' => 'text',
					'section' => 'editing',
					'required' => true,
					'default' => $this->requestManager->getTarget(),
					'validation-callback' => [ $this, 'isValidDatabase' ],
					'disabled' => $this->requestManager->isLocked(),
				],
				'edit-reason' => [
					'type' => 'textarea',
					'rows' => 4,
					'label-message' => 'requestssl-label-reason',
					'section' => 'editing',
					'default' => $this->requestManager->getReason(),
					'disabled' => $this->requestManager->isLocked(),
					'raw' => true,
				],
				'submit-edit' => [
					'type' => 'submit',
					'default' => $this->context->msg( 'requestssl-label-edit-request' )->text(),
					'section' => 'editing',
					'disabled' => $this->requestManager->isLocked(),
				],
			];
		}

		if ( $this->permissionManager->userHasRight( $user, 'handle-custom-domain-requests' ) ) {
			$validRequest = true;
			$status = $this->requestManager->getStatus();

			$info = new MessageWidget( [
				'label' => new HtmlSnippet(
						$this->context->msg( 'requestssl-info-groups',
							$this->requestManager->getRequester()->getName(),
							$this->requestManager->getTarget(),
							$this->context->getLanguage()->commaList(
								$this->requestManager->getUserGroupsFromTarget()
							)
						)->escaped(),
					),
				'type' => 'notice',
			] );

			if ( $this->requestManager->getRequester()->getBlock() ) {
				$info .= new MessageWidget( [
					'label' => new HtmlSnippet(
							$this->context->msg( 'requestssl-info-requester-blocked',
								$this->requestManager->getRequester()->getName(),
								WikiMap::getCurrentWikiId()
							)->escaped()
						),
					'type' => 'warning',
				] );
			}

			if ( $this->requestManager->getRequester()->isLocked() ) {
				$info .= new MessageWidget( [
					'label' => new HtmlSnippet(
								$this->context->msg( 'requestssl-info-requester-locked',
								$this->requestManager->getRequester()->getName()
							)->escaped()
						),
					'type' => 'error',
				] );

				$validRequest = false;
				if ( $status === 'pending' || $status === 'inprogress' ) {
					$status = 'declined';
				}
			}

			$formDescriptor += [
				'handle-info' => [
					'type' => 'info',
					'default' => (string)$info,
					'raw' => true,
					'section' => 'handling',
				],
				'handle-lock' => [
					'type' => 'check',
					'label-message' => 'requestssl-label-lock',
					'default' => $this->requestManager->isLocked(),
					'section' => 'handling',
				],
			];

			$formDescriptor += [
				'handle-status' => [
					'type' => 'select',
					'label-message' => 'requestssl-label-update-status',
					'options-messages' => [
						'requestssl-label-pending' => 'pending',
						'requestssl-label-inprogress' => 'inprogress',
						'requestssl-label-complete' => 'complete',
						'requestssl-label-declined' => 'declined',
					],
					'default' => $status,
					'disabled' => !$validRequest,
					'cssclass' => 'requestssl-infuse',
					'section' => 'handling',
				],
				'handle-comment' => [
					'type' => 'textarea',
					'rows' => 4,
					'label-message' => 'requestssl-label-status-updated-comment',
					'section' => 'handling',
				],
				'submit-handle' => [
					'type' => 'submit',
					'default' => $this->context->msg( 'htmlform-submit' )->text(),
					'section' => 'handling',
				],
			];

			if (
				$this->config->get( 'RequestCustomDomainCloudflareConfig' )['apikey'] &&
				$this->config->get( 'RequestCustomDomainCloudflareConfig' )['zoneid'] &&
				$this->requestManager->getStatus() === 'pending'
			) {
				$formDescriptor['handle-cf'] = [
					'type' => 'submit',
					'flags' => [ 'progressive', 'primary' ],
					'buttonlabel-message' => 'requestssl-label-cloudflare-handle',
					'section' => 'handling',
				];
			}
		}

		return $formDescriptor;
	}

	/**
	 * @param ?string $comment
	 * @param array $alldata
	 * @return string|bool|Message
	 */
	public function isValidComment( ?string $comment, array $alldata ) {
		if ( isset( $alldata['submit-comment'] ) && ( !$comment || ctype_space( $comment ) ) ) {
			return $this->context->msg( 'htmlform-required' );
		}

		return true;
	}

	/**
	 * @param ?string $target
	 * @return string|bool|Message
	 */
	public function isValidDatabase( ?string $target ) {
		if ( !in_array( $target, $this->config->get( MainConfigNames::LocalDatabases ), true ) ) {
			return $this->context->msg( 'requestssl-invalid-target' );
		}

		return true;
	}

	/**
	 * @param ?string $url
	 * @param array $alldata
	 * @return string|bool
	 */

	/**
	 * @param int $requestID
	 * @return ?RequestSSLOOUIForm
	 */
	public function getForm( int $requestID ): ?RequestSSLOOUIForm {
		$this->requestManager->fromID( $requestID );
		$out = $this->context->getOutput();

		if ( $requestID === 0 || !$this->requestManager->exists() ) {
			$out->addHTML(
				Html::errorBox( $this->context->msg( 'requestssl-unknown' )->escaped() )
			);

			return null;
		}

		$out->addModules( [ 'ext.requestssl.oouiform' ] );
		$out->addModuleStyles( [ 'ext.requestssl.oouiform.styles' ] );
		$out->addModuleStyles( [ 'oojs-ui-widgets.styles' ] );

		$formDescriptor = $this->getFormDescriptor();
		$htmlForm = new RequestSSLOOUIForm( $formDescriptor, $this->context, 'requestssl-section' );

		$htmlForm->setId( 'requestssl-request-viewer' );
		$htmlForm->suppressDefaultSubmit();
		$htmlForm->setSubmitCallback(
			function ( array $formData, HTMLForm $form ) {
				return $this->submitForm( $formData, $form, );
			}
		);

		return $htmlForm;
	}

	/**
	 * @param array $formData
	 * @param HTMLForm $form
	 */
	protected function submitForm(
		array $formData,
		HTMLForm $form,
	) {
		$user = $form->getUser();
		if ( !$user->isRegistered() ) {
			throw new UserNotLoggedIn( 'exception-nologin-text', 'exception-nologin' );
		}

		$out = $form->getContext()->getOutput();

		if ( isset( $formData['handle-cf'] ) ) {
			$this->requestManager->queryCloudflare();

			$out->addHTML(
				Html::successBox(
					Html::element(
						'p',
						[],
						$this->context->msg( 'requestssl-cloudflare-label-success' )->text()
					),
					'mw-notify-success'
				)
			);

			return;
		}

		if ( isset( $formData['submit-comment'] ) ) {
			$this->requestManager->addComment( $formData['comment'], $user );
			$out->addHTML(
				Html::successBox(
					Html::element(
						'p',
						[],
						$this->context->msg( 'requestssl-comment-success' )->text()
					),
					'mw-notify-success'
				)
			);

			return;
		}

		$oldStatus = $this->requestManager->getStatus();

		if ( isset( $formData['submit-edit'] ) ) {
			$this->requestManager->startAtomic( __METHOD__ );

			$changes = [];
			if ( $this->requestManager->getReason() !== ( $formData['edit-reason'] ?? '' ) ) {
				$changes[] = $this->context->msg( 'requestssl-request-edited-reason' )->plaintextParams(
					$this->requestManager->getReason(),
					$formData['edit-reason']
				)->escaped();

				$this->requestManager->setReason( $formData['edit-reason'] );
			}

			if ( $this->requestManager->getCustomDomain() !== $formData['edit-source'] ) {
				$changes[] = $this->context->msg( 'requestssl-request-edited-source' )->plaintextParams(
					$this->requestManager->getCustomDomain(),
					$formData['edit-source']
				)->escaped();

				$this->requestManager->setCustomDomain( $formData['edit-source'] );
			}

			if ( $this->requestManager->getTarget() !== $formData['edit-target'] ) {
				$changes[] = $this->context->msg(
					'requestssl-request-edited-target',
					$this->requestManager->getTarget(),
					$formData['edit-target']
				)->escaped();

				$this->requestManager->setTarget( $formData['edit-target'] );
			}

			if ( !$changes ) {
				$this->requestManager->endAtomic( __METHOD__ );

				$this->context->getOutput()->addHTML(
					Html::warningBox(
						Html::element(
							'p',
							[],
							$this->context->msg( 'requestssl-no-changes' )->text()
						),
						'mw-notify-error'
					)
				);
				return;
			}

			if ( $this->requestManager->getStatus() === 'declined' ) {
				$this->requestManager->setStatus( 'pending' );

				$comment = $this->context->msg( 'requestssl-request-reopened', $user->getName() )->rawParams(
					implode( "\n\n", $changes )
				)->inContentLanguage()->escaped();

				$this->requestManager->logStatusUpdate( $comment, 'pending', $user );

				$this->requestManager->addComment( $comment, User::newSystemUser( 'RequestCustomDomain Extension' ) );

				$this->requestManager->sendNotification(
					$comment, 'requestssl-request-status-update', $user
				);
			} else {
				$comment = $this->context->msg( 'requestssl-request-edited', $user->getName() )->rawParams(
					implode( "\n\n", $changes )
				)->inContentLanguage()->escaped();

				$this->requestManager->addComment( $comment, User::newSystemUser( 'RequestCustomDomain Extension' ) );
			}

			$this->requestManager->endAtomic( __METHOD__ );

			$out->addHTML(
				Html::successBox(
					Html::element(
						'p',
						[],
						$this->context->msg( 'requestssl-edit-success' )->text()
					),
					'mw-notify-success'
				)
			);

			return;
		}

		if ( isset( $formData['submit-handle'] ) ) {
			$this->requestManager->startAtomic( __METHOD__ );
			$changes = [];

			if ( $this->requestManager->isLocked() !== (bool)$formData['handle-lock'] ) {
				$changes[] = $this->requestManager->isLocked() ?
					'unlocked' : 'locked';

				$this->requestManager->setLocked( (int)$formData['handle-lock'] );
			}

			if ( $this->requestManager->getStatus() === $formData['handle-status'] ) {
				$this->requestManager->endAtomic( __METHOD__ );

				if ( !$changes ) {
					$out->addHTML(
						Html::warningBox(
							Html::element(
								'p',
								[],
								$this->context->msg( 'requestssl-no-changes' )->text()
							),
							'mw-notify-error'
						)
					);
					return;
				}

				if ( in_array( 'locked', $changes ) ) {
					$out->addHTML(
						Html::successBox(
							Html::element(
								'p',
								[],
								$this->context->msg( 'requestssl-success-locked' )->text()
							),
							'mw-notify-success'
						)
					);
				}

				if ( in_array( 'unlocked', $changes ) ) {
					$out->addHTML(
						Html::successBox(
							Html::element(
								'p',
								[],
								$this->context->msg( 'requestssl-success-unlocked' )->text()
							),
							'mw-notify-success'
						)
					);
				}

				return;
			}

			$this->requestManager->setStatus( $formData['handle-status'] );

			$statusMessage = $this->context->msg( 'requestssl-label-' . $formData['handle-status'] )
				->inContentLanguage()
				->text();

			$comment = $this->context->msg( 'requestssl-status-updated', mb_strtolower( $statusMessage ) )
				->inContentLanguage()
				->escaped();

			if ( $oldStatus !== 'complete' && $formData['handle-status'] === 'complete' ) {
				$serverNameUpdated = $this->requestManager->updateServerName();
				if ( $serverNameUpdated && ExtensionRegistry::getInstance()->isLoaded( 'ManageWiki' ) ) {
					$this->requestManager->logToManageWiki( $this->context->getUser() );
				}
			}

			if ( $formData['handle-comment'] ) {
				$commentUser = User::newSystemUser( 'RequestCustomDomain Status Update' );

				$comment .= "\n" . $this->context->msg( 'requestssl-comment-given', $user->getName() )
					->inContentLanguage()
					->escaped();

				$comment .= ' ' . $formData['handle-comment'];
			}

			$this->requestManager->addComment( $comment, $commentUser ?? $user );
			$this->requestManager->logStatusUpdate(
				$formData['handle-comment'], $formData['handle-status'], $user
			);

			$this->requestManager->sendNotification( $comment, 'requestssl-request-status-update', $user );

			$this->requestManager->endAtomic( __METHOD__ );

			$out->addHTML(
				Html::successBox(
					Html::element(
						'p',
						[],
						$this->context->msg( 'requestssl-status-updated-success' )->text()
					),
					'mw-notify-success'
				)
			);
		}
	}
}
