<?php

namespace Miraheze\RequestSSL;

use OOUI\FieldsetLayout;
use OOUI\HtmlSnippet;
use OOUI\IndexLayout;
use OOUI\PanelLayout;
use OOUI\TabPanelLayout;
use OOUI\Widget;
use MediaWiki\HTMLForm\OOUIHTMLForm;
use MediaWiki\Xml\Xml;

class RequestSSLOOUIForm extends OOUIHTMLForm {

	/** @var bool */
	protected $mSubSectionBeforeFields = false;

	/**
	 * @param string $html
	 * @return string
	 */
	public function wrapForm( $html ) {
		$html = Xml::tags( 'div', [ 'id' => 'requestssl' ], $html );

		return parent::wrapForm( $html );
	}

	/**
	 * @param string $legend
	 * @param string $section
	 * @param array $attributes
	 * @param bool $isRoot
	 * @return PanelLayout
	 */
	protected function wrapFieldSetSection( $legend, $section, $attributes, $isRoot ) {
		$layout = parent::wrapFieldSetSection( $legend, $section, $attributes, $isRoot );

		$layout->addClasses( [ 'requestssl-fieldset-wrapper' ] );
		$layout->removeClasses( [ 'oo-ui-panelLayout-framed' ] );

		return $layout;
	}

	/**
	 * @return string
	 */
	public function getBody() {
		$tabPanels = [];
		foreach ( $this->mFieldTree as $key => $val ) {
			if ( !is_array( $val ) ) {
				wfDebug( __METHOD__ . " encountered a field not attached to a section: '{$key}'" );

				continue;
			}

			$label = $this->getLegend( $key );

			$content =
				$this->getHeaderHtml( $key ) .
				$this->displaySection(
					$val,
					'',
					"mw-section-{$key}-"
				) .
				$this->getFooterHtml( $key );

			$tabPanels[] = new TabPanelLayout( 'mw-section-' . $key, [
				'classes' => [ 'mw-htmlform-autoinfuse-lazy' ],
				'label' => $label,
				'content' => new FieldsetLayout( [
					'classes' => [ 'requestssl-section-fieldset' ],
					'id' => "mw-section-{$key}",
					'label' => $label,
					'items' => [
						new Widget( [
							'content' => new HtmlSnippet( $content )
						] ),
					],
				] ),
				'expanded' => false,
				'framed' => true,
			] );
		}

		$indexLayout = new IndexLayout( [
			'infusable' => true,
			'expanded' => false,
			'autoFocus' => false,
			'classes' => [ 'requestssl-tabs' ],
		] );

		$indexLayout->addTabPanels( $tabPanels );

		$header = $this->formatFormHeader();

		$form = new PanelLayout( [
			'framed' => true,
			'expanded' => false,
			'classes' => [ 'requestssl-tabs-wrapper' ],
			'content' => $indexLayout
		] );

		return $header . $form;
	}
}
