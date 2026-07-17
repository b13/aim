<?php

declare(strict_types=1);

/*
 * This file is part of TYPO3 CMS-based extension "aim" by b13.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 */

namespace B13\Aim\Tests\Unit\Controller;

use B13\Aim\Backend\SortUrlBuilder;
use B13\Aim\Capability\ConversationCapableInterface;
use B13\Aim\Capability\EmbeddingCapableInterface;
use B13\Aim\Capability\ImageGenerationCapableInterface;
use B13\Aim\Capability\TextGenerationCapableInterface;
use B13\Aim\Controller\ProviderController;
use B13\Aim\Domain\Model\AiProviderManifest;
use B13\Aim\Domain\Model\ProviderConfiguration;
use B13\Aim\Domain\Repository\ProviderConfigurationRepository;
use B13\Aim\Domain\Repository\RequestLogRepository;
use B13\Aim\Provider\AiProviderInterface;
use B13\Aim\Provider\LiveModelDiscovery;
use B13\Aim\Registry\AiProviderRegistry;
use B13\Aim\Registry\DisabledModelRegistry;
use B13\Aim\Request\ConversationRequest;
use B13\Aim\Request\EmbeddingRequest;
use B13\Aim\Request\ImageGenerationRequest;
use B13\Aim\Request\TextGenerationRequest;
use B13\Aim\Response\ConversationResponse;
use B13\Aim\Response\EmbeddingResponse;
use B13\Aim\Response\GeneratedImage;
use B13\Aim\Response\ImageGenerationResponse;
use B13\Aim\Response\TextResponse;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Registry;

/**
 * Regression coverage for the "connection check always probes with a
 * conversational/text prompt, even when the configured model doesn't support
 * conversation" bug: SymfonyAiPlatformAdapter implements every capability
 * interface regardless of which specific model a configuration points at, so
 * `$provider instanceof ConversationCapableInterface` was always true for any
 * OpenAI-backed config, including embeddings-only or image-generation-only
 * models, which then received a request type the real API rejects.
 */
final class ProviderControllerTest extends TestCase
{
    #[Test]
    public function embeddingsOnlyModelIsProbedWithAnEmbeddingRequestNotConversation(): void
    {
        $configuration = new ProviderConfiguration([
            'uid' => 3,
            'ai_provider' => 'openai',
            'model' => 'text-embedding-3-large',
        ]);

        $fakeProvider = new class implements AiProviderInterface, ConversationCapableInterface, TextGenerationCapableInterface, EmbeddingCapableInterface {
            public function processConversationRequest(ConversationRequest $request): ConversationResponse
            {
                throw new \RuntimeException('conversation probe must not be sent to an embeddings-only model');
            }

            public function processTextGenerationRequest(TextGenerationRequest $request): TextResponse
            {
                throw new \RuntimeException('text-generation probe must not be sent to an embeddings-only model');
            }

            public function processEmbeddingRequest(EmbeddingRequest $request): EmbeddingResponse
            {
                return new EmbeddingResponse([[0.1, 0.2, 0.3]]);
            }
        };

        $manifest = $this->manifest([
            'text-embedding-3-large' => [EmbeddingCapableInterface::class],
            'o3-mini' => [ConversationCapableInterface::class, TextGenerationCapableInterface::class],
        ], $fakeProvider);

        $response = $this->verify($manifest, $configuration);

        self::assertTrue($response['ok'], 'Expected the embeddings probe to succeed, got: ' . ($response['message'] ?? ''));
    }

    #[Test]
    public function imageGenerationOnlyModelIsProbedWithAnImageGenerationRequest(): void
    {
        $configuration = new ProviderConfiguration([
            'uid' => 5,
            'ai_provider' => 'openai',
            'model' => 'gpt-image-1',
        ]);

        $fakeProvider = new class implements AiProviderInterface, ConversationCapableInterface, TextGenerationCapableInterface, ImageGenerationCapableInterface {
            public function processConversationRequest(ConversationRequest $request): ConversationResponse
            {
                throw new \RuntimeException('conversation probe must not be sent to an image-generation-only model');
            }

            public function processTextGenerationRequest(TextGenerationRequest $request): TextResponse
            {
                throw new \RuntimeException('text-generation probe must not be sent to an image-generation-only model');
            }

            public function processImageGenerationRequest(ImageGenerationRequest $request): ImageGenerationResponse
            {
                return new ImageGenerationResponse([GeneratedImage::fromBase64('base64data', 'image/png')]);
            }
        };

        $manifest = $this->manifest([
            'gpt-image-1' => [ImageGenerationCapableInterface::class],
            'o3-mini' => [ConversationCapableInterface::class, TextGenerationCapableInterface::class],
        ], $fakeProvider);

        $response = $this->verify($manifest, $configuration);

        self::assertTrue($response['ok'], 'Expected the image-generation probe to succeed, got: ' . ($response['message'] ?? ''));
    }

    /**
     * @param array<string, list<class-string>> $modelCapabilities
     */
    private function manifest(array $modelCapabilities, AiProviderInterface $fakeProvider): AiProviderManifest
    {
        $container = $this->createStub(ContainerInterface::class);
        $container->method('get')->willReturn($fakeProvider);

        return new AiProviderManifest(
            identifier: 'openai',
            name: 'OpenAI',
            description: '',
            iconIdentifier: '',
            supportedModels: [],
            capabilities: [
                ConversationCapableInterface::class,
                TextGenerationCapableInterface::class,
                EmbeddingCapableInterface::class,
                ImageGenerationCapableInterface::class,
            ],
            serviceName: 'aim.symfony_ai.openai',
            container: $container,
            modelCapabilities: $modelCapabilities,
        );
    }

    /**
     * @return array{ok: bool, message?: string}
     */
    private function verify(AiProviderManifest $manifest, ProviderConfiguration $configuration): array
    {
        $configurationRepository = $this->createStub(ProviderConfigurationRepository::class);
        $configurationRepository->method('findByUid')->with($configuration->uid)->willReturn($configuration);

        $providerRegistry = $this->createStub(AiProviderRegistry::class);
        $providerRegistry->method('hasProvider')->willReturn(true);
        $providerRegistry->method('getProvider')->willReturn($manifest);

        $registry = $this->createStub(Registry::class);
        $registry->method('get')->willReturn([]);

        // ModuleTemplateFactory, LiveModelDiscovery, and SortUrlBuilder are `final`
        // classes PHPUnit cannot generate test doubles for — and
        // verifyProviderAction() never touches any of them, so an uninitialized
        // instance is a sufficient placeholder.
        $moduleTemplateFactory = (new \ReflectionClass(ModuleTemplateFactory::class))->newInstanceWithoutConstructor();
        $liveModelDiscovery = (new \ReflectionClass(LiveModelDiscovery::class))->newInstanceWithoutConstructor();
        $sortUrlBuilder = (new \ReflectionClass(SortUrlBuilder::class))->newInstanceWithoutConstructor();

        $controller = new ProviderController(
            $this->createStub(IconFactory::class),
            $this->createStub(UriBuilder::class),
            $configurationRepository,
            $providerRegistry,
            $moduleTemplateFactory,
            $this->createStub(DisabledModelRegistry::class),
            $this->createStub(RequestLogRepository::class),
            $registry,
            $liveModelDiscovery,
            $sortUrlBuilder,
        );

        $request = $this->createStub(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn(['uid' => $configuration->uid]);

        $response = $controller->verifyProviderAction($request);

        return json_decode((string)$response->getBody(), true);
    }
}
