<?php

declare(strict_types=1);

namespace OCA\Bestatter\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\FeaturePolicy;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\Util;

class PageController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private IURLGenerator $urlGenerator,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		Util::addScript($this->appName, 'main');
		Util::addStyle($this->appName, 'style');
		$response = new TemplateResponse($this->appName, 'main', [
			'dashboardUrl' => $this->urlGenerator->linkToRoute('bestatter.dashboardApi.dashboard'),
			'teamUrl' => $this->urlGenerator->linkToRoute('bestatter.dashboardApi.team'),
			'casesUrl' => $this->urlGenerator->linkToRoute('bestatter.caseApi.listCases'),
			'customizingUrl' => $this->urlGenerator->linkToRoute('bestatter.catalogApi.customizing'),
			'caseSchemaUrl' => $this->urlGenerator->linkToRoute('bestatter.catalogApi.caseSchema'),
			'appVersion' => \OCA\Bestatter\AppInfo\Application::VERSION,
		]);
		$featurePolicy = new FeaturePolicy();
		$featurePolicy->addAllowedMicrophoneDomain("'self'");
		$response->setFeaturePolicy($featurePolicy);
		// 0.89.0: PDF-Vorschauen (Auftrag, Rechnung, Unterschrift am Tablet) werden als blob:-Adresse in einem iframe gezeigt.
		// Ohne frame-src blob: blockiert der Browser sie (Nextcloud setzt nur default-src 'none').
		$csp = new ContentSecurityPolicy();
		$csp->addAllowedFrameDomain('blob:');
		$response->setContentSecurityPolicy($csp);
		return $response;
	}
}
