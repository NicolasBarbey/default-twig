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

use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;

final readonly class RefusedCreationDraft
{
    private const string SESSION_PREFIX = 'bo.search_dictionary.refused_creation.';

    public function keep(Request $request, FormInterface $form): void
    {
        $data = $form->getData();

        if (!\is_array($data) || !$request->hasSession()) {
            return;
        }

        $errors = [];
        foreach ($form->getErrors(true) as $error) {
            $errors[] = $error->getMessage();
        }

        $request->getSession()->set(self::SESSION_PREFIX.$form->getName(), [
            'data' => array_filter($data, static fn (mixed $value): bool => null === $value || \is_scalar($value)),
            'errors' => array_values(array_unique($errors)),
        ]);
    }

    /**
     * @return array{data: array<string, scalar|null>, errors: list<string>}|null
     */
    public function take(Request $request, string $formName): ?array
    {
        if (!$request->hasSession()) {
            return null;
        }

        $draft = $request->getSession()->remove(self::SESSION_PREFIX.$formName);

        return \is_array($draft) && \is_array($draft['data'] ?? null) && \is_array($draft['errors'] ?? null) ? $draft : null;
    }

    /**
     * @param list<string> $errors
     */
    public static function showErrors(FormInterface $form, array $errors): void
    {
        foreach ($errors as $error) {
            $form->addError(new FormError($error));
        }
    }
}
