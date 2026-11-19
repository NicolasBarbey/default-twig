<?php

declare(strict_types=1);

/*
 * This file is part of the Thelia package.
 * http://www.thelia.net
 *
 * (c) OpenStudio <info@thelia.net>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace BackOfficeDefaultTwigBundle\Service\SearchDictionary;

use BackOfficeDefaultTwigBundle\Service\Admin\AdminAccessChecker;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFailureMessage;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFlash;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminFormToken;
use BackOfficeDefaultTwigBundle\Service\Admin\AdminLogger;
use Symfony\Component\DependencyInjection\Attribute\Target;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Thelia\Core\Security\AccessManager;
use Thelia\Core\Security\Resource\AdminResources;
use Thelia\Core\Security\SecurityContext;
use Thelia\Core\Security\User\UserInterface;
use Thelia\Domain\Search\Dictionary\SearchDictionaryImporter;
use Thelia\Domain\Search\Dictionary\SearchDictionaryImportReport;

final readonly class SearchDictionaryImportAction
{
    public const KIND_SYNONYMS = 'synonyms';
    public const KIND_REDIRECTS = 'redirects';

    private const ACCEPTED_EXTENSIONS = ['csv', 'txt'];
    private const REFUSED_ROWS_SHOWN = 20;

    public function __construct(
        private AdminAccessChecker $access,
        private AdminFormToken $formToken,
        private AdminFlash $flash,
        private AdminLogger $adminLogger,
        private SearchDictionaryImporter $importer,
        private SearchDictionaryLanguage $languages,
        private UrlGeneratorInterface $urls,
        private TranslatorInterface $translator,
        private SecurityContext $securityContext,
        #[Target('search_dictionary_import')]
        private RateLimiterFactoryInterface $limiter,
    ) {
    }

    public function launch(Request $request, string $kind, string $listRoute): Response
    {
        if ($denied = $this->access->check(AdminResources::CONFIG, [], [AccessManager::CREATE, AccessManager::UPDATE])) {
            return $denied;
        }

        $back = new RedirectResponse($this->urls->generate($listRoute, ['language_id' => (int) $this->languages->resolve($request->request->get('language_id'))->getId()]));

        if (0 === $request->request->count() && 0 === $request->files->count() && (int) $request->server->get('CONTENT_LENGTH', 0) > 0) {
            $this->flash->add($request, 'danger', $this->translator->trans('The file is larger than the server accepts: post_max_size is %size%.', ['%size%' => (string) \ini_get('post_max_size')]));

            return $back;
        }

        if (!$this->formToken->isValid($request)) {
            return $back;
        }

        $file = $request->files->get('file');
        $refusal = $this->refusalOf($file);
        if (null !== $refusal || !$file instanceof UploadedFile) {
            $this->flash->add($request, 'danger', $refusal ?? $this->translator->trans('Choose a CSV file to import.'));

            return $back;
        }

        $adminUser = $this->securityContext->getAdminUser();
        if (!$this->limiter->create($adminUser instanceof UserInterface ? $adminUser->getUsername() : null)->consume()->isAccepted()) {
            $this->flash->add($request, 'warning', $this->translator->trans('Too many imports in a short time: wait a minute before the next one.'));

            return $back;
        }

        try {
            $report = self::KIND_SYNONYMS === $kind
                ? $this->importer->importSynonyms($file->getPathname())
                : $this->importer->importRedirects($file->getPathname());
        } catch (\Throwable $exception) {
            $this->flash->add($request, 'danger', AdminFailureMessage::of($exception, $this->translator));

            return $back;
        }

        $this->adminLogger->log(AdminResources::CONFIG, AccessManager::CREATE, \sprintf('Search dictionary import (%s): %d created, %d updated, %d refused', $kind, $report->created, $report->updated, \count($report->refused)));
        $this->flashReport($request, $report);

        return $back;
    }

    private function refusalOf(mixed $file): ?string
    {
        if (!$file instanceof UploadedFile) {
            return $this->translator->trans('Choose a CSV file to import.');
        }

        if (!$file->isValid()) {
            return $this->translator->trans('The file could not be uploaded.');
        }

        if (!\in_array(strtolower($file->getClientOriginalExtension()), self::ACCEPTED_EXTENSIONS, true)) {
            return $this->translator->trans('The file must be a CSV file (.csv or .txt).');
        }

        if ((int) $file->getSize() > SearchDictionaryImporter::MAX_BYTES) {
            return $this->translator->trans('The file is larger than %size% KB.', ['%size%' => (string) (SearchDictionaryImporter::MAX_BYTES / 1024)]);
        }

        return null;
    }

    private function flashReport(Request $request, SearchDictionaryImportReport $report): void
    {
        $this->flash->add($request, 'success', $this->translator->trans('Import finished: %created% created, %updated% updated.', ['%created%' => $report->created, '%updated%' => $report->updated]));

        if ([] === $report->refused) {
            return;
        }

        $lines = [];
        foreach (\array_slice($report->refused, 0, self::REFUSED_ROWS_SHOWN, true) as $line => $reason) {
            $lines[] = $this->translator->trans('line %line%: %reason%', ['%line%' => $line, '%reason%' => $reason]);
        }

        $message = $this->translator->trans('%count% rows refused.', ['%count%' => \count($report->refused)]).' '.implode(' | ', $lines);
        if (\count($report->refused) > self::REFUSED_ROWS_SHOWN) {
            $message .= ' '.$this->translator->trans('And %count% more.', ['%count%' => \count($report->refused) - self::REFUSED_ROWS_SHOWN]);
        }

        $this->flash->add($request, 'warning', $message);
    }
}
