<?php

namespace Miraheze\RequestSSL;

use MediaWiki\Config\Config;
use MediaWiki\Context\IContextSource;
use MediaWiki\Html\Html;
use MediaWiki\HTMLForm\HTMLForm;
use MediaWiki\Linker\Linker;
use MediaWiki\Message\Message;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\User\User;
use MediaWiki\WikiMap\WikiMap;
use OOUI\HtmlSnippet;
use OOUI\MessageWidget;
use UserNotLoggedIn;

class RequestSSLViewer {

	/** @var Config */
	private $config;

	/** @var IContextSource */
	private $context;

	/** @var RequestSSLManager */
	private $requestSslRequestManager;

	/** @var PermissionManager */
	private $permissionManager;

	/**
	 * @param Config $config
	 * @param IContextSource $context
	 * @param RequestSSLManager $requestSslRequestManager
	 * @param PermissionManager $permissionManager
	 */
	public function __construct(
		Config $config,
		IContextSource $context,
		RequestSSLManager $requestSslRequestManager,
		PermissionManager $permissionManager
	) {
		$this->config = $config;
		$this->context = $context;
		$this->requestSslRequestManager = $requestSslRequestManager;
		$this->permissionManager = $permissionManager;
	}

	/**
	 * @return array
	 */
	public function getFormDescriptor(): array {
		$user = $this->context->getUser();

		if (
			$this->requestSslRequestManager->isPrivate() &&
			$user->getName() !== $this->requestSslRequestManager->getRequester()->getName() &&
			!$this->permissionManager->userHasRight( $user, 'view-private-ssl-requests' )
		) {
			$this->context->getOutput()->addHTML(
				Html::warningBox(
					Html::element(
						'p',
						[],
						$this->context->msg( 'requestssl-private' )->text()
					),
					'mw-notify-error'
				)
			);

			return [];
		}

		if ( $this->requestSslRequestManager->isLocked() ) {
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
				'default' => $this->requestSslRequestManager->getCustomDomain(),
			],
			'target' => [
				'label-message' => 'requestssl-label-target',
				'type' => 'text',
				'readonly' => true,
				'section' => 'details',
				'default' => $this->requestSslRequestManager->getTarget(),
			],
			'requester' => [
				'label-message' => 'requestssl-label-requester',
				'type' => 'info',
				'section' => 'details',
				'default' => htmlspecialchars( $this->requestSslRequestManager->getRequester()->getName() ) .
					Linker::userToolLinks(
						$this->requestSslRequestManager->getRequester()->getId(),
						$this->requestSslRequestManager->getRequester()->getName()
					),
				'raw' => true,
			],
			'requestedDate' => [
				'label-message' => 'requestssl-label-requested-date',
				'type' => 'info',
				'section' => 'details',
				'default' => $this->context->getLanguage()->timeanddate(
					$this->requestSslRequestManager->getTimestamp(), true
				),
			],
			'status' => [
				'label-message' => 'requestssl-label-status',
				'type' => 'text',
				'readonly' => true,
				'section' => 'details',
				'default' => $this->context->msg(
					'requestssl-label-' . $this->requestSslRequestManager->getStatus()
				)->text(),
			],
			'reason' => [
				'type' => 'textarea',
				'rows' => 4,
				'readonly' => true,
				'label-message' => 'requestssl-label-reason',
				'default' => $this->requestSslRequestManager->getReason(),
				'raw' => true,
				'cssclass' => 'requestssl-infuse',
				'section' => 'details',
			],
		];

		foreach ( $this->requestSslRequestManager->getComments() as $comment ) {
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

		if (
			$this->permissionManager->userHasRight( $user, 'handle-ssl-requests' ) ||
			$user->getActorId() === $this->requestSslRequestManager->getRequester()->getActorId()
		) {
			$formDescriptor += [
				'comment' => [
					'type' => 'textarea',
					'rows' => 4,
					'label-message' => 'requestssl-label-comment',
					'section' => 'comments',
					'validation-callback' => [ $this, 'isValidComment' ],
					'disabled' => $this->requestSslRequestManager->isLocked(),
				],
				'submit-comment' => [
					'type' => 'submit',
					'default' => $this->context->msg( 'requestssl-label-add-comment' )->text(),
					'section' => 'comments',
					'disabled' => $this->requestSslRequestManager->isLocked(),
				],
				'edit-source' => [
					'label-message' => 'requestssl-label-customdomain',
					'type' => 'url',
					'section' => 'editing',
					'required' => true,
					'default' => $this->requestSslRequestManager->getCustomDomain(),
					'disabled' => $this->requestSslRequestManager->isLocked(),
				],
				'edit-target' => [
					'label-message' => 'requestssl-label-target',
					'type' => 'text',
					'section' => 'editing',
					'required' => true,
					'default' => $this->requestSslRequestManager->getTarget(),
					'validation-callback' => [ $this, 'isValidDatabase' ],
					'disabled' => $this->requestSslRequestManager->isLocked(),
				],
				'edit-reason' => [
					'type' => 'textarea',
					'rows' => 4,
					'label-message' => 'requestssl-label-reason',
					'section' => 'editing',
					'required' => true,
					'default' => $this->requestSslRequestManager->getReason(),
					'validation-callback' => [ $this, 'isValidReason' ],
					'disabled' => $this->requestSslRequestManager->isLocked(),
					'raw' => true,
				],
				'submit-edit' => [
					'type' => 'submit',
					'default' => $this->context->msg( 'requestssl-label-edit-request' )->text(),
					'section' => 'editing',
					'disabled' => $this->requestSslRequestManager->isLocked(),
				],
			];
		}

		if ( $this->permissionManager->userHasRight( $user, 'handle-ssl-requests' ) ) {
			$validRequest = true;
			$status = $this->requestSslRequestManager->getStatus();
			$fileInfo = $this->context->msg( 'requestssl-info-command' )->plaintextParams(
					$this->requestSslRequestManager->getCommand()
				)->parse();

				$fileInfo .= Html::element( 'button', [
						'type' => 'button',
						'onclick' => 'navigator.clipboard.writeText( 
      								$( \'.oo-ui-flaggedElement-notice code\' ).text() );',
					],
					$this->context->msg( 'requestssl-button-copy' )->text()
				);
				$info = new MessageWidget( [
					'label' => new HtmlSnippet( $fileInfo ),
					'type' => 'notice',
				] );

			$info .= new MessageWidget( [
				'label' => new HtmlSnippet(
						$this->context->msg( 'requestssl-info-groups',
							$this->requestSslRequestManager->getRequester()->getName(),
							$this->requestSslRequestManager->getTarget(),
							$this->context->getLanguage()->commaList(
								$this->requestSslRequestManager->getUserGroupsFromTarget()
							)
						)->escaped(),
					),
				'type' => 'notice',
			] );

			if ( $this->requestSslRequestManager->isPrivate() ) {
				$info .= new MessageWidget( [
					'label' => new HtmlSnippet( $this->context->msg( 'requestssl-info-request-private' )->escaped() ),
					'type' => 'warning',
				] );
			}

			if ( $this->requestSslRequestManager->getRequester()->getBlock() ) {
				$info .= new MessageWidget( [
					'label' => new HtmlSnippet(
							$this->context->msg( 'requestssl-info-requester-locally-blocked',
								$this->requestSslRequestManager->getRequester()->getName(),
								WikiMap::getCurrentWikiId()
							)->escaped()
						),
					'type' => 'warning',
				] );
			}

			// @phan-suppress-next-line PhanDeprecatedFunction Only for MW 1.39 or lower.
			if ( $this->requestSslRequestManager->getRequester()->getGlobalBlock() ) {
				$info .= new MessageWidget( [
					'label' => new HtmlSnippet(
								$this->context->msg( 'requestssl-info-requester-globally-blocked',
								$this->requestSslRequestManager->getRequester()->getName()
							)->escaped()
						),
					'type' => 'error',
				] );

				$validRequest = false;
				if ( $status === 'pending' || $status === 'inprogress' ) {
					$status = 'declined';
				}
			}

			if ( $this->requestSslRequestManager->getRequester()->isLocked() ) {
				$info .= new MessageWidget( [
					'label' => new HtmlSnippet(
								$this->context->msg( 'requestssl-info-requester-locked',
								$this->requestSslRequestManager->getRequester()->getName()
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
					'default' => $info,
					'raw' => true,
					'section' => 'handling',
				],
				'handle-lock' => [
					'type' => 'check',
					'label-message' => 'requestssl-label-lock',
					'default' => $this->requestSslRequestManager->isLocked(),
					'section' => 'handling',
				],
			];

			if ( $this->permissionManager->userHasRight( $user, 'view-private-ssl-requests' ) ) {
				$formDescriptor += [
					'handle-private' => [
						'type' => 'check',
						'label-message' => 'requestssl-label-private',
						'default' => $this->requestSslRequestManager->isPrivate(),
						'disabled' => $this->requestSslRequestManager->isPrivate( true ),
						'section' => 'handling',
					],
				];
			}

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
		if ( !in_array( $target, $this->config->get( 'LocalDatabases' ) ) ) {
			return $this->context->msg( 'requestssl-invalid-target' );
		}

		return true;
	}

	/**
	 * @param ?string $reason
	 * @return string|bool|Message
	 */
	public function isValidReason( ?string $reason ) {
		if ( !$reason || ctype_space( $reason ) ) {
			return $this->context->msg( 'htmlform-required' );
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
		$this->requestSslRequestManager->fromID( $requestID );
		$out = $this->context->getOutput();

		if ( $requestID === 0 || !$this->requestSslRequestManager->exists() ) {
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

		if ( isset( $formData['submit-comment'] ) ) {
			$this->requestSslRequestManager->addComment( $formData['comment'], $user );
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

		$oldStatus = $this->requestSslRequestManager->getStatus();

		if ( isset( $formData['submit-edit'] ) ) {
			$this->requestSslRequestManager->startAtomic( __METHOD__ );

			$changes = [];
			if ( $this->requestSslRequestManager->getReason() !== $formData['edit-reason'] ) {
				$changes[] = $this->context->msg( 'requestssl-request-edited-reason' )->plaintextParams(
					$this->requestSslRequestManager->getReason(),
					$formData['edit-reason']
				)->escaped();

				$this->requestSslRequestManager->setReason( $formData['edit-reason'] );
			}

			if ( $this->requestSslRequestManager->getCustomDomain() !== $formData['edit-source'] ) {
				$changes[] = $this->context->msg( 'requestssl-request-edited-source' )->plaintextParams(
					$this->requestSslRequestManager->getCustomDomain(),
					$formData['edit-source']
				)->escaped();

				$this->requestSslRequestManager->setCustomDomain( $formData['edit-source'] );
			}

			if ( $this->requestSslRequestManager->getTarget() !== $formData['edit-target'] ) {
				$changes[] = $this->context->msg(
					'requestssl-request-edited-target',
					$this->requestSslRequestManager->getTarget(),
					$formData['edit-target']
				)->escaped();

				$this->requestSslRequestManager->setTarget( $formData['edit-target'] );
			}

			if ( !$changes ) {
				$this->requestSslRequestManager->endAtomic( __METHOD__ );

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

			if ( $this->requestSslRequestManager->getStatus() === 'declined' ) {
				$this->requestSslRequestManager->setStatus( 'pending' );

				$comment = $this->context->msg( 'requestssl-request-reopened', $user->getName() )->rawParams(
					implode( "\n\n", $changes )
				)->inContentLanguage()->escaped();

				$this->requestSslRequestManager->logStatusUpdate( $comment, 'pending', $user );

				$this->requestSslRequestManager->addComment( $comment, User::newSystemUser( 'RequestSSL Extension' ) );

				$this->requestSslRequestManager->sendNotification(
					$comment, 'requestssl-request-status-update', $user
				);
			} else {
				$comment = $this->context->msg( 'requestssl-request-edited', $user->getName() )->rawParams(
					implode( "\n\n", $changes )
				)->inContentLanguage()->escaped();

				$this->requestSslRequestManager->addComment( $comment, User::newSystemUser( 'RequestSSL Extension' ) );
			}

			$this->requestSslRequestManager->endAtomic( __METHOD__ );

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
			$this->requestSslRequestManager->startAtomic( __METHOD__ );
			$changes = [];

			if ( $this->requestSslRequestManager->isLocked() !== (bool)$formData['handle-lock'] ) {
				$changes[] = $this->requestSslRequestManager->isLocked() ?
					'unlocked' : 'locked';

				$this->requestSslRequestManager->setLocked( (int)$formData['handle-lock'] );
			}

			if (
				isset( $formData['handle-private'] ) &&
				$this->requestSslRequestManager->isPrivate() !== (bool)$formData['handle-private']
			) {
				$changes[] = $this->requestSslRequestManager->isPrivate() ?
					'public' : 'private';

				$this->requestSslRequestManager->setPrivate( (int)$formData['handle-private'] );
			}

			if ( $this->requestSslRequestManager->getStatus() === $formData['handle-status'] ) {
				$this->requestSslRequestManager->endAtomic( __METHOD__ );

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

				if ( in_array( 'private', $changes ) ) {
					$out->addHTML(
						Html::successBox(
							Html::element(
								'p',
								[],
								$this->context->msg( 'requestssl-success-private' )->text()
							),
							'mw-notify-success'
						)
					);
				}

				if ( in_array( 'public', $changes ) ) {
					$out->addHTML(
						Html::successBox(
							Html::element(
								'p',
								[],
								$this->context->msg( 'requestssl-success-public' )->text()
							),
							'mw-notify-success'
						)
					);
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

			$this->requestSslRequestManager->setStatus( $formData['handle-status'] );

			$statusMessage = $this->context->msg( 'requestssl-label-' . $formData['handle-status'] )
				->inContentLanguage()
				->text();

			$comment = $this->context->msg( 'requestssl-status-updated', strtolower( $statusMessage ) )
				->inContentLanguage()
				->escaped();

			if ( $oldStatus !== 'complete' && $formData['handle-status'] === 'complete' ) {
				$serverNameUpdated = $this->requestSslRequestManager->updateServerName();
				if ( $serverNameUpdated && ExtensionRegistry::getInstance()->isLoaded( 'ManageWiki' ) ) {
					$this->requestSslRequestManager->logToManageWiki( $this->context->getUser() );
				}
			}

			if ( $formData['handle-comment'] ) {
				$commentUser = User::newSystemUser( 'RequestSSL Status Update' );

				$comment .= "\n" . $this->context->msg( 'requestssl-comment-given', $user->getName() )
					->inContentLanguage()
					->escaped();

				$comment .= ' ' . $formData['handle-comment'];
			}

			$this->requestSslRequestManager->addComment( $comment, $commentUser ?? $user );
			$this->requestSslRequestManager->logStatusUpdate(
				$formData['handle-comment'], $formData['handle-status'], $user
			);

			$this->requestSslRequestManager->sendNotification( $comment, 'requestssl-request-status-update', $user );

			$this->requestSslRequestManager->endAtomic( __METHOD__ );

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
