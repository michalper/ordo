<?php
declare(strict_types=1);

namespace Ordo\Automation\Plugin\Webapi;

use Magento\Framework\Webapi\Rest\Request as RestRequest;
use Magento\Framework\Webapi\Rest\Response\FieldsFilter;
use Magento\Framework\Webapi\ServiceOutputProcessor;

/**
 * Applies the standard Magento `fields` sparse-fieldset query param (e.g.
 * `?fields=items[entity_id,name]`) to this module's REST responses.
 *
 * Magento core ships the parsing/filtering logic for this
 * (`Magento\Framework\Webapi\Rest\Response\FieldsFilter`, since ~2015) but never actually wires
 * it into anything in this Magento version — no core plugin, controller, or di.xml calls it, so
 * `?fields=...` is silently ignored on every webapi.xml-backed endpoint, this module's included.
 * Confirmed by grepping the installed `vendor/magento` tree for any reference to `FieldsFilter`
 * outside its own class/test — there is none.
 *
 * `Magento\Sales\Plugin\Webapi\OrderResponseNullKeysPlugin` (module-sales) established the
 * pattern this follows: an `afterProcess` plugin on `ServiceOutputProcessor`, scoped to the
 * `webapi_rest` area only (see etc/webapi_rest/di.xml) so SOAP/GraphQL/async requests - which
 * don't have a REST query string to read `fields` from - are never touched.
 *
 * Scoped to this module's own `Ordo\Automation\Api\*` service interfaces, not applied globally,
 * to keep the blast radius limited to the endpoints ROADMAP.md/API.md actually document this
 * for.
 */
class SparseFieldsetPlugin
{
    private const string NAMESPACE_PREFIX = 'Ordo\\Automation\\Api\\';

    public function __construct(
        private readonly FieldsFilter $fieldsFilter,
        private readonly RestRequest $request
    ) {
    }

    /**
     * @param mixed $result
     * @param mixed $data
     * @return mixed
     */
    public function afterProcess(
        ServiceOutputProcessor $subject,
        $result,
        $data,
        string $serviceClassName,
        string $serviceMethodName
    ) {
        if (!str_starts_with($serviceClassName, self::NAMESPACE_PREFIX) || !is_array($result)) {
            return $result;
        }

        $filtered = $this->fieldsFilter->filter($result);

        // FieldsFilter::filter() returns [] both when no/invalid `fields` param was given and
        // when the filter legitimately produces an empty result - only trust it once we know a
        // usable filter string was actually present, otherwise a request with no `fields` param
        // at all would come back empty instead of unfiltered.
        return $filtered !== [] || $this->hasUsableFieldsParam() ? $filtered : $result;
    }

    private function hasUsableFieldsParam(): bool
    {
        $fields = $this->request->getParam(FieldsFilter::FILTER_PARAMETER);

        return is_string($fields) && $fields !== '';
    }
}
