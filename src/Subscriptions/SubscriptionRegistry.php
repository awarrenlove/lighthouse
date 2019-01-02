<?php

namespace Nuwave\Lighthouse\Subscriptions;

use GraphQL\Error\SyntaxError;
use GraphQL\Language\Parser;
use GraphQL\Language\AST\Node;
use Illuminate\Support\Collection;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use Nuwave\Lighthouse\Schema\Types\GraphQLSubscription;
use Nuwave\Lighthouse\Schema\Types\NotFoundSubscription;
use Nuwave\Lighthouse\Subscriptions\Contracts\ContextSerializer;

class SubscriptionRegistry
{
    /**
     * @var ContextSerializer
     */
    protected $serializer;

    /**
     * @var StorageManager
     */
    protected $storage;

    /**
     * A map from operation names to channel names.
     *
     * @var string[]
     */
    protected $subscribers = [];

    /**
     * Active subscription fields of the schema.
     *
     * @var GraphQLSubscription[]
     */
    protected $subscriptions = [];

    /**
     * @param ContextSerializer $serializer
     * @param StorageManager    $storage
     */
    public function __construct(ContextSerializer $serializer, StorageManager $storage)
    {
        $this->serializer = $serializer;
        $this->storage = $storage;
    }

    /**
     * Add subscription to registry.
     *
     * @param GraphQLSubscription $subscription
     * @param string              $field
     *
     * @return SubscriptionRegistry
     */
    public function register(GraphQLSubscription $subscription, string $field): self
    {
        $this->subscriptions[$field] = $subscription;

        return $this;
    }

    /**
     * Check if subscription is registered.
     *
     * @param string $key
     *
     * @return bool
     */
    public function has(string $key): bool
    {
        return isset($this->subscriptions[$key]);
    }

    /**
     * Get subscription keys.
     *
     * @return string[]
     */
    public function keys(): array
    {
        return array_keys($this->subscriptions);
    }

    /**
     * Get instance of subscription.
     *
     * @param string $key
     *
     * @return GraphQLSubscription
     */
    public function subscription(string $key): GraphQLSubscription
    {
        return $this->subscriptions[$key];
    }

    /**
     * Add subscription to registry.
     *
     * @param Subscriber $subscriber
     * @param string     $channel
     *
     * @return $this
     */
    public function subscriber(Subscriber $subscriber, string $channel): self
    {
        if ($subscriber->channel) {
            $this->storage->storeSubscriber($subscriber, $channel);
        }

        $this->subscribers[$subscriber->operationName] = $subscriber->channel;

        return $this;
    }

    /**
     * Get registered subscriptions.
     *
     * @param Subscriber $subscriber
     *
     * @throws SyntaxError
     *
     * @return Collection
     */
    public function subscriptions(Subscriber $subscriber): Collection
    {
        // A subscription can be fired w/out a request so we must make
        // sure the schema has been generated.
        app('graphql')->prepSchema();

        $documentNode = Parser::parse($subscriber->queryString, [
            'noLocation' => true,
        ]);

        return collect($documentNode->definitions)
            ->filter(function (Node $node): bool {
                return $node instanceof OperationDefinitionNode;
            })
            ->filter(function (OperationDefinitionNode $node): bool {
                return $node->operation === 'subscription';
            })
            ->flatMap(function (OperationDefinitionNode $node) {
                return collect($node->selectionSet->selections)
                    ->map(function (FieldNode $field): string {
                        return $field->name->value;
                    })
                    ->toArray();
            })
            ->map(function ($subscriptionField): GraphQLSubscription {
                return array_get(
                    $this->subscriptions,
                    $subscriptionField,
                    new NotFoundSubscription()
                );
            });
    }

    /**
     * Get all current subscribers.
     *
     * @return string[]
     */
    public function toArray(): array
    {
        return $this->subscribers;
    }

    /**
     * Reset collection of subscribers.
     *
     * @return void
     */
    public function reset(): void
    {
        $this->subscribers = [];
    }
}
