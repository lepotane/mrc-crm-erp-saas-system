<?php

namespace App\Integration\Gib;

use App\Integration\Canonical\CanonicalVoucher;
use App\Integration\Canonical\CanonicalVoucherLine;

/**
 * Minimal UBL-TR 1.2 invoice builder.
 *
 * MRC only produces the XML document: no digital signature, no GIB submission.
 * The signed/ sent copy is handled by a licensed private integrator.
 * This class has no package dependencies outside PHP core extensions.
 */
final class UblBuilder
{
    private const NS_INVOICE = 'urn:oasis:names:specification:ubl:schema:xsd:Invoice-2';

    private const NS_CBC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2';

    private const NS_CAC = 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2';

    public function build(CanonicalVoucher $voucher): string
    {
        $data = [
            'version' => '2.1',
            'customization' => 'TR1.2',
            'id' => $voucher->voucherNumber,
            'issue_date' => $voucher->date->toDateString(),
            'currency' => $voucher->currency !== '' ? $voucher->currency : 'TRY',
            'uuid' => $voucher->ettn ?? '',
            'tax_id' => $voucher->taxId ?? '',
            'description' => $voucher->description ?? '',
            'lines' => [],
        ];

        foreach ($voucher->lines as $index => $line) {
            $amount = $this->lineAmount($line);
            $data['lines'][] = [
                'id' => (string) ($index + 1),
                'quantity' => '1',
                'amount' => $amount,
                'name' => $line->description ?? $line->accountCode,
                'price' => $amount,
            ];
        }

        // XMLWriter escapes values itself; the heredoc fallback escapes them
        // explicitly in buildWithHeredoc().
        return class_exists(\XMLWriter::class)
            ? $this->buildWithWriter($data)
            : $this->buildWithHeredoc($data);
    }

    /** @param array<string,mixed> $data */
    private function buildWithWriter(array $data): string
    {
        $writer = new \XMLWriter;
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->setIndentString('  ');
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('Invoice');
        $writer->writeAttribute('xmlns', self::NS_INVOICE);
        $writer->writeAttribute('xmlns:cbc', self::NS_CBC);
        $writer->writeAttribute('xmlns:cac', self::NS_CAC);

        $writer->writeElement('cbc:UBLVersionID', $data['version']);
        $writer->writeElement('cbc:CustomizationID', $data['customization']);
        $writer->writeElement('cbc:ID', $data['id']);
        $writer->writeElement('cbc:IssueDate', $data['issue_date']);
        $writer->writeElement('cbc:DocumentCurrencyCode', $data['currency']);
        if ($data['uuid'] !== '') {
            $writer->writeElement('cbc:UUID', $data['uuid']);
        }

        // Minimal party mapping: the voucher carries one tax id (the
        // counterparty). Both party blocks expose it as TaxIdentificationID.
        foreach (['cac:AccountingSupplierParty', 'cac:AccountingCustomerParty'] as $party) {
            $writer->startElement($party);
            $writer->startElement('cac:Party');
            $writer->startElement('cac:PartyTaxScheme');
            $writer->writeElement('cbc:TaxIdentificationID', $data['tax_id']);
            $writer->endElement();
            if ($data['description'] !== '') {
                $writer->startElement('cac:PartyLegalEntity');
                $writer->writeElement('cbc:RegistrationName', $data['description']);
                $writer->endElement();
            }
            $writer->endElement();
            $writer->endElement();
        }

        foreach ($data['lines'] as $line) {
            $writer->startElement('cac:InvoiceLine');
            $writer->writeElement('cbc:ID', $line['id']);
            $writer->startElement('cbc:InvoicedQuantity');
            $writer->writeAttribute('unitCode', 'C62');
            $writer->text($line['quantity']);
            $writer->endElement();
            $writer->startElement('cbc:LineExtensionAmount');
            $writer->writeAttribute('currencyID', $data['currency']);
            $writer->text($line['amount']);
            $writer->endElement();
            $writer->startElement('cac:Item');
            $writer->writeElement('cbc:Name', $line['name']);
            $writer->endElement();
            $writer->startElement('cac:Price');
            $writer->startElement('cbc:PriceAmount');
            $writer->writeAttribute('currencyID', $data['currency']);
            $writer->text($line['price']);
            $writer->endElement();
            $writer->endElement();
            $writer->endElement();
        }

        $writer->endElement(); // Invoice
        $writer->endDocument();

        return (string) $writer->outputMemory();
    }

    /** @param array<string,mixed> $data */
    private function buildWithHeredoc(array $data): string
    {
        $esc = fn (mixed $value): string => $this->escape((string) $value);

        $lines = '';
        foreach ($data['lines'] as $line) {
            $lines .= <<<XML
                <cac:InvoiceLine>
                  <cbc:ID>{$esc($line['id'])}</cbc:ID>
                  <cbc:InvoicedQuantity unitCode="C62">{$esc($line['quantity'])}</cbc:InvoicedQuantity>
                  <cbc:LineExtensionAmount currencyID="{$esc($data['currency'])}">{$esc($line['amount'])}</cbc:LineExtensionAmount>
                  <cac:Item><cbc:Name>{$esc($line['name'])}</cbc:Name></cac:Item>
                  <cac:Price><cbc:PriceAmount currencyID="{$esc($data['currency'])}">{$esc($line['price'])}</cbc:PriceAmount></cac:Price>
                </cac:InvoiceLine>
            XML;
            $lines .= "\n";
        }

        $parties = '';
        foreach (['AccountingSupplierParty', 'AccountingCustomerParty'] as $party) {
            $legal = $data['description'] !== ''
                ? "\n        <cac:PartyLegalEntity><cbc:RegistrationName>{$esc($data['description'])}</cbc:RegistrationName></cac:PartyLegalEntity>"
                : '';
            $parties .= <<<XML
                  <cac:{$party}>
                    <cac:Party>
                      <cac:PartyTaxScheme><cbc:TaxIdentificationID>{$esc($data['tax_id'])}</cbc:TaxIdentificationID></cac:PartyTaxScheme>{$legal}
                    </cac:Party>
                  </cac:{$party}>
            XML;
            $parties .= "\n";
        }

        $uuid = $data['uuid'] !== '' ? "  <cbc:UUID>{$esc($data['uuid'])}</cbc:UUID>\n" : '';
        $namespaces = 'xmlns="'.$this->escape(self::NS_INVOICE).'"'
            .' xmlns:cbc="'.$this->escape(self::NS_CBC).'"'
            .' xmlns:cac="'.$this->escape(self::NS_CAC).'"';

        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <Invoice {$namespaces}>
          <cbc:UBLVersionID>{$esc($data['version'])}</cbc:UBLVersionID>
          <cbc:CustomizationID>{$esc($data['customization'])}</cbc:CustomizationID>
          <cbc:ID>{$esc($data['id'])}</cbc:ID>
          <cbc:IssueDate>{$esc($data['issue_date'])}</cbc:IssueDate>
          <cbc:DocumentCurrencyCode>{$esc($data['currency'])}</cbc:DocumentCurrencyCode>
        {$uuid}{$parties}{$lines}</Invoice>
        XML;
    }

    private function lineAmount(CanonicalVoucherLine $line): string
    {
        $debit = $this->numeric($line->debit);
        $credit = $this->numeric($line->credit);
        $useDebit = function_exists('bccomp')
            ? bccomp($debit, $credit, 4) >= 0
            : (float) $debit >= (float) $credit;

        return number_format((float) ($useDebit ? $debit : $credit), 2, '.', '');
    }

    private function numeric(string $value): string
    {
        $value = str_replace(',', '.', trim($value));

        return is_numeric($value) ? $value : '0';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
