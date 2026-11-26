<?php

namespace App\Models\Product;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductVariant extends Model
{
    protected $fillable = [
        'product_id',
        'sku',
        'attributes',
        'images',
        'price',
        'stock',
        'low_stock_threshold',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'attributes' => 'array',
            'images' => 'array',
            'price' => 'decimal:2',
            'stock' => 'integer',
            'low_stock_threshold' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function hasOwnPrice(): bool
    {
        return $this->price !== null && (float) $this->price > 0;
    }

    public function effectivePrice(?Product $product = null): string
    {
        if ($this->hasOwnPrice()) {
            return number_format((float) $this->price, 2, '.', '');
        }

        $parent = $product ?? ($this->relationLoaded('product') ? $this->product : $this->product()->first());

        return $parent ? $parent->effectivePrice() : '0.00';
    }

    /**
     * @return array<string, string>
     */
    public function normalizedAttributes(): array
    {
        return self::normalizeAttributeMap($this->getAttribute('attributes'));
    }

    /**
     * Normalize variant attribute key-value pairs and repair shifted Amazon Twister dimension labels.
     *
     * @return array<string, string>
     */
    public static function normalizeAttributeMap(mixed $rawAttributes, ?string $fallbackLabel = null, ?string $fallbackValue = null): array
    {
        $attrs = [];
        if (is_array($rawAttributes)) {
            foreach ($rawAttributes as $k => $v) {
                $key = trim((string) $k);
                $val = is_scalar($v) ? trim((string) $v) : '';
                if ($key !== '' && $val !== '') {
                    $attrs[$key] = $val;
                }
            }
        }

        if ($attrs === [] && $fallbackValue !== null && trim($fallbackValue) !== '') {
            $lbl = trim((string) ($fallbackLabel ?? 'Option')) ?: 'Option';
            $val = trim($fallbackValue);
            $labelParts = array_values(array_filter(array_map('trim', explode(' / ', $lbl)), fn (string $s): bool => $s !== ''));
            $valueParts = array_values(array_filter(array_map('trim', explode(' / ', $val)), fn (string $s): bool => $s !== ''));
            if (count($labelParts) > 1 && count($labelParts) === count($valueParts)) {
                foreach ($labelParts as $i => $partLabel) {
                    $attrs[$partLabel] = $valueParts[$i];
                }
            } else {
                $attrs[$lbl] = $val;
            }
        }

        // Repair shifted Amazon Twister keys where "Color" received storage capacity (e.g. "256 GB")
        if (
            isset($attrs['Color'], $attrs['Memory Storage Capacity'], $attrs['Set name'])
            && preg_match('/^\d+\s*(?:GB|TB|MB)$/i', $attrs['Color']) === 1
            && preg_match('/^\d+\s*(?:GB|TB|MB)$/i', $attrs['Memory Storage Capacity']) !== 1
        ) {
            $storageVal = $attrs['Color'];
            $modelVal = $attrs['Memory Storage Capacity'];
            $colorVal = $attrs['Set name'];
            $attrs['Color'] = $colorVal;
            $attrs['Memory Storage Capacity'] = $storageVal;
            $attrs['Set name'] = $modelVal;
        }

        return $attrs;
    }
}
