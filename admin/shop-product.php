<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/auth.php';
require_once dirname(__DIR__) . '/app/shop.php';
require_once dirname(__DIR__) . '/app/shop-catalog.php';
require_once dirname(__DIR__) . '/app/shipping.php';
require_once dirname(__DIR__) . '/app/photo-upload.php';
require_once dirname(__DIR__) . '/app/role-display.php';

require_role('owner');
start_llama_session();

$db = db();
$user = current_user();

if (!$user) {
    http_response_code(401);
    exit('Authentication required.');
}

$ownerId = (int) $user['id'];

llama_ensure_shop_storage($db);
llama_ensure_shop_catalog_storage($db);
llama_ensure_shipping_storage($db);

$primaryRoleLabel = llama_primary_role_label($ownerId);
$primaryRoleIcon = llama_primary_role_icon($ownerId);

if (empty($_SESSION['owner_shop_product_csrf'])) {
    $_SESSION['owner_shop_product_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = (string) $_SESSION['owner_shop_product_csrf'];

function shop_editor_e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function shop_editor_csrf(string $expected): void
{
    $submitted = $_POST['csrf_token'] ?? '';
    if (!is_string($submitted) || $submitted === '' || !hash_equals($expected, $submitted)) {
        throw new RuntimeException('Your session could not be verified. Reload the page and try again.');
    }
}

function shop_editor_slug(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-');
}

function shop_editor_sku_piece(string $value): string
{
    $value = strtoupper(trim($value));
    $value = preg_replace('/[^A-Z0-9]+/', '-', $value) ?? '';
    return trim($value, '-');
}

function shop_editor_money_to_cents(mixed $value): int
{
    $value = str_replace(['$', ',', ' '], '', trim((string) $value));
    if ($value === '' || !preg_match('/^\d+(?:\.\d{1,2})?$/', $value)) {
        throw new InvalidArgumentException('Enter a valid price with no more than two decimal places.');
    }
    [$whole, $decimal] = array_pad(explode('.', $value, 2), 2, '');
    $decimal = str_pad($decimal, 2, '0');
    return ((int) $whole * 100) + (int) substr($decimal, 0, 2);
}

function shop_editor_optional_money_to_cents(mixed $value): ?int
{
    return trim((string) $value) === '' ? null : shop_editor_money_to_cents($value);
}

function shop_editor_money_input(?int $cents): string
{
    return $cents === null ? '' : number_format($cents / 100, 2, '.', '');
}

function shop_editor_variant_pairs(array $variant): array
{
    if (!empty($variant['_attribute_pairs']) && is_array($variant['_attribute_pairs'])) {
        return $variant['_attribute_pairs'];
    }
    $pairs = [];
    foreach ([['option_one_name','option_one_value'],['option_two_name','option_two_value'],['option_three_name','option_three_value']] as [$nameKey,$valueKey]) {
        $name = trim((string) ($variant[$nameKey] ?? ''));
        $value = trim((string) ($variant[$valueKey] ?? ''));
        if ($name !== '' && $value !== '') $pairs[] = ['name'=>$name,'value'=>$value];
    }
    return $pairs;
}

function shop_editor_variant_name(array $pairs): string
{
    $values = [];
    foreach ($pairs as $pair) {
        $value = trim((string) ($pair['value'] ?? ''));
        if ($value !== '') $values[] = $value;
    }
    return $values ? implode(' / ', $values) : 'Standard';
}

function shop_editor_delete_uploaded_file(string $url): void
{
    $url = trim($url);
    if (!str_starts_with($url, '/uploads/shop-products/')) return;
    $root = dirname(__DIR__);
    $absolute = $root . $url;
    $uploadRoot = realpath($root . '/uploads/shop-products');
    $directory = realpath(dirname($absolute));
    if ($uploadRoot === false || $directory === false || !str_starts_with($directory, $uploadRoot)) return;
    if (is_file($absolute)) @unlink($absolute);
}

function shop_editor_redirect(int $productId, string $notice): never
{
    header('Location: /shop-product.php?id=' . $productId . '&notice=' . rawurlencode($notice));
    exit;
}

function shop_editor_photo_assignment_label(?string $name, ?string $value): string
{
    $name = trim((string) $name);
    $value = trim((string) $value);
    return ($name === '' || $value === '') ? 'Entire product' : $name . ': ' . $value;
}

$productId = (int) ($_GET['id'] ?? $_POST['product_id'] ?? 0);
$isEditing = $productId > 0;
$product = null;

if ($isEditing) {
    $stmt = $db->prepare('SELECT * FROM shop_products WHERE id = ? LIMIT 1');
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$product) { http_response_code(404); exit('Product not found.'); }
}

$name = $product['name'] ?? '';
$slug = $product['slug'] ?? '';
$shortDescription = $product['short_description'] ?? '';
$description = $product['description'] ?? '';
$productType = $product['product_type'] ?? '';
$status = $product['status'] ?? LLAMA_SHOP_PRODUCT_DRAFT;
$isFeatured = !empty($product['is_featured']);
$requiresShipping = !isset($product['requires_shipping']) || !empty($product['requires_shipping']);
$sortOrder = (int) ($product['sort_order'] ?? 0);
$error = '';
$notice = trim((string) ($_GET['notice'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        shop_editor_csrf($csrfToken);
        $action = trim((string) ($_POST['action'] ?? ''));

        if ($action === 'save_product') {
            $name = trim((string) ($_POST['name'] ?? ''));
            $submittedSlug = trim((string) ($_POST['slug'] ?? ''));
            $slug = shop_editor_slug($submittedSlug !== '' ? $submittedSlug : $name);
            $shortDescription = trim((string) ($_POST['short_description'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $productType = trim((string) ($_POST['product_type'] ?? ''));
            $status = trim((string) ($_POST['status'] ?? LLAMA_SHOP_PRODUCT_DRAFT));
            $isFeatured = isset($_POST['is_featured']);
            $requiresShipping = isset($_POST['requires_shipping']);
            $sortOrder = (int) ($_POST['sort_order'] ?? 0);

            if ($name === '') throw new InvalidArgumentException('Product name is required.');
            if ($slug === '') throw new InvalidArgumentException('Product URL slug is invalid.');
            if (!in_array($status,[LLAMA_SHOP_PRODUCT_DRAFT,LLAMA_SHOP_PRODUCT_ACTIVE,LLAMA_SHOP_PRODUCT_ARCHIVED],true)) throw new InvalidArgumentException('Invalid product status.');

            $slugCheck = $db->prepare('SELECT id FROM shop_products WHERE slug = ? AND id <> ? LIMIT 1');
            $slugCheck->execute([$slug,$productId]);
            if ($slugCheck->fetchColumn()) throw new InvalidArgumentException('Another product already uses that URL slug.');

            if ($isEditing) {
                $update = $db->prepare('UPDATE shop_products SET name=?,slug=?,short_description=?,description=?,product_type=?,status=?,is_featured=?,requires_shipping=?,sort_order=? WHERE id=? LIMIT 1');
                $update->execute([$name,$slug,$shortDescription!==''?$shortDescription:null,$description!==''?$description:null,$productType!==''?$productType:null,$status,$isFeatured?1:0,$requiresShipping?1:0,$sortOrder,$productId]);
                shop_editor_redirect($productId,'Product saved.');
            }

            $insert = $db->prepare('INSERT INTO shop_products (name,slug,short_description,description,product_type,status,is_featured,requires_shipping,sort_order) VALUES (?,?,?,?,?,?,?,?,?)');
            $insert->execute([$name,$slug,$shortDescription!==''?$shortDescription:null,$description!==''?$description:null,$productType!==''?$productType:null,$status,$isFeatured?1:0,$requiresShipping?1:0,$sortOrder]);
            $productId = (int) $db->lastInsertId();
            shop_editor_redirect($productId,'Product created. Build its variants next.');
        }

        if (!$isEditing) throw new RuntimeException('Create the product first.');

        if ($action === 'save_options') {
            $hasOptions = isset($_POST['has_options']);
            $optionDefinitions = [];
            $existingStmt = $db->prepare('SELECT * FROM shop_product_variants WHERE product_id = ? ORDER BY id ASC');
            $existingStmt->execute([$productId]);
            $existingVariants = $existingStmt->fetchAll(PDO::FETCH_ASSOC);
            $existingPairs = [];

            foreach ($existingVariants as $existingVariant) {
                $variantId = (int) $existingVariant['id'];
                $pairs = llama_shop_variant_values($db,$variantId);
                if (!$pairs) $pairs = shop_editor_variant_pairs($existingVariant);
                $existingPairs[$variantId] = $pairs;
            }

            if ($hasOptions) {
                $submittedAttributes = $_POST['attributes'] ?? [];
                if (!is_array($submittedAttributes)) throw new InvalidArgumentException('Variant attributes are invalid.');
                $allowedNames = llama_shop_variant_attribute_names();
                $usedNames = [];
                foreach ($submittedAttributes as $attribute) {
                    if (!is_array($attribute)) continue;
                    $attributeName = trim((string) ($attribute['name'] ?? ''));
                    if ($attributeName === '') continue;
                    if (!in_array($attributeName,$allowedNames,true)) throw new InvalidArgumentException('Unknown variant attribute: '.$attributeName);
                    if (isset($usedNames[$attributeName])) throw new InvalidArgumentException($attributeName.' can only be added once.');
                    $submittedValues = $attribute['values'] ?? [];
                    if (!is_array($submittedValues)) $submittedValues = [];
                    $allowedValues = llama_shop_variant_attribute_values($attributeName);
                    $cleanValues = [];
                    foreach ($submittedValues as $value) {
                        $value = trim((string) $value);
                        if ($value !== '' && in_array($value,$allowedValues,true)) $cleanValues[$value] = $value;
                    }
                    $cleanValues = array_values($cleanValues);
                    if (!$cleanValues) throw new InvalidArgumentException('Choose at least one value for '.$attributeName.'.');
                    $usedNames[$attributeName] = true;
                    $optionDefinitions[] = ['name'=>$attributeName,'values'=>$cleanValues];
                }
                if (!$optionDefinitions) throw new InvalidArgumentException('Add at least one variant attribute or turn off product choices.');
            }

            llama_shop_save_product_options($db,$productId,$optionDefinitions);
            $defaultPrice = shop_editor_money_to_cents($_POST['default_price'] ?? '0');
            $skuPrefix = shop_editor_sku_piece((string) ($_POST['sku_prefix'] ?? $slug));
            if ($skuPrefix === '') $skuPrefix = 'LS-'.$productId;
            $combinations = llama_shop_option_combinations($optionDefinitions);
            if (!$combinations) $combinations = [[]];
            if (count($combinations) > 500) throw new RuntimeException('These selections would create more than 500 variants. Reduce the selected values before saving.');

            $existingByKey = [];
            foreach ($existingVariants as $existingVariant) {
                $variantId = (int) $existingVariant['id'];
                $existingByKey[llama_shop_variant_value_key($existingPairs[$variantId] ?? [])] = $existingVariant;
            }

            $insert = $db->prepare("INSERT INTO shop_product_variants (product_id,sku,name,option_one_name,option_one_value,option_two_name,option_two_value,option_three_name,option_three_value,price_cents,currency,track_inventory,inventory_quantity,allow_backorder,fulfillment_type,is_active,sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,'usd',0,0,0,?,1,?)");
            $validVariantIds = [];

            foreach ($combinations as $index=>$pairs) {
                $key = llama_shop_variant_value_key($pairs);
                if (isset($existingByKey[$key])) {
                    $variantId = (int) $existingByKey[$key]['id'];
                    $validVariantIds[$variantId] = true;
                    llama_shop_set_variant_values($db,$productId,$variantId,$pairs);
                    continue;
                }
                $skuParts = [$skuPrefix];
                foreach ($pairs as $pair) {
                    $piece = shop_editor_sku_piece((string) ($pair['value'] ?? ''));
                    if ($piece !== '') $skuParts[] = $piece;
                }
                if (!$pairs) $skuParts[] = 'STD';
                $baseSku = implode('-',$skuParts);
                $candidateSku = $baseSku;
                $suffix = 2;
                while (true) {
                    $skuCheck = $db->prepare('SELECT id FROM shop_product_variants WHERE sku = ? LIMIT 1');
                    $skuCheck->execute([$candidateSku]);
                    if (!$skuCheck->fetchColumn()) break;
                    $candidateSku = $baseSku.'-'.$suffix++;
                }
                $first=$pairs[0]??[]; $second=$pairs[1]??[]; $third=$pairs[2]??[];
                $insert->execute([$productId,$candidateSku,shop_editor_variant_name($pairs),$first['name']??null,$first['value']??null,$second['name']??null,$second['value']??null,$third['name']??null,$third['value']??null,$defaultPrice,LLAMA_SHOP_FULFILLMENT_MANUAL,$index]);
                $variantId = (int) $db->lastInsertId();
                llama_shop_set_variant_values($db,$productId,$variantId,$pairs);
                $validVariantIds[$variantId] = true;
            }

            $historyCheck=$db->prepare('SELECT COUNT(*) FROM shop_order_items WHERE variant_id = ?');
            $deleteShipping=$db->prepare('DELETE FROM shop_shipping_profiles WHERE variant_id = ?');
            $deleteVariant=$db->prepare('DELETE FROM shop_product_variants WHERE id = ? AND product_id = ? LIMIT 1');
            $deactivate=$db->prepare('UPDATE shop_product_variants SET is_active = 0 WHERE id = ? AND product_id = ? LIMIT 1');
            foreach ($existingVariants as $existingVariant) {
                $existingVariantId=(int)$existingVariant['id'];
                if (isset($validVariantIds[$existingVariantId])) continue;
                $historyCheck->execute([$existingVariantId]);
                if ((int)$historyCheck->fetchColumn()>0) { $deactivate->execute([$existingVariantId,$productId]); continue; }
                $deleteShipping->execute([$existingVariantId]);
                $deleteVariant->execute([$existingVariantId,$productId]);
            }
            shop_editor_redirect($productId,'Variant attributes saved and combinations rebuilt.');
        }

        if ($action === 'upload_photos') {
            if (empty($_FILES['product_photos']) || !is_array($_FILES['product_photos'])) throw new InvalidArgumentException('Choose one or more product photos.');
            $photos = llama_store_uploaded_photos($_FILES['product_photos'],$ownerId,'shop-products',20);
            llama_shop_add_product_images($db,$productId,$photos,null,null);
            shop_editor_redirect($productId,count($photos).' product photo'.(count($photos)===1?'':'s').' uploaded. Assign each photo below.');
        }

        if ($action === 'save_photo') {
            $imageId=(int)($_POST['image_id']??0);
            if ($imageId<1) throw new InvalidArgumentException('Invalid product photo.');
            $assignment=trim((string)($_POST['photo_assignment']??''));
            $optionName=null; $optionValue=null;
            if ($assignment!=='') {
                $decoded=json_decode($assignment,true);
                if (!is_array($decoded)) throw new InvalidArgumentException('Photo assignment is invalid.');
                $optionName=trim((string)($decoded['name']??''));
                $optionValue=trim((string)($decoded['value']??''));
                if ($optionName===''||$optionValue==='') { $optionName=null; $optionValue=null; }
            }
            $altText=trim((string)($_POST['alt_text']??''));
            if (mb_strlen($altText)>300) throw new InvalidArgumentException('Photo alt text must be 300 characters or fewer.');
            $check=$db->prepare('SELECT id FROM shop_product_images WHERE id = ? AND product_id = ? LIMIT 1');
            $check->execute([$imageId,$productId]);
            if (!$check->fetchColumn()) throw new RuntimeException('Product photo not found.');
            $update=$db->prepare('UPDATE shop_product_images SET alt_text=?,option_name=?,option_value=? WHERE id=? AND product_id=? LIMIT 1');
            $update->execute([$altText!==''?$altText:null,$optionName,$optionValue,$imageId,$productId]);
            if (isset($_POST['make_primary'])) llama_shop_set_primary_image($db,$productId,$imageId);
            shop_editor_redirect($productId,'Photo assignment saved.');
        }

        if ($action === 'delete_image') {
            $imageId=(int)($_POST['image_id']??0);
            $deletedUrl=llama_shop_delete_product_image($db,$productId,$imageId);
            if ($deletedUrl!==null) shop_editor_delete_uploaded_file($deletedUrl);
            shop_editor_redirect($productId,'Product photo deleted.');
        }

        if ($action === 'delete_variant') {
            $variantId=(int)($_POST['variant_id']??0);
            if ($variantId<1) throw new InvalidArgumentException('Invalid variant.');
            $variantStmt=$db->prepare('SELECT id FROM shop_product_variants WHERE id=? AND product_id=? LIMIT 1');
            $variantStmt->execute([$variantId,$productId]);
            if (!$variantStmt->fetchColumn()) throw new RuntimeException('Variant not found.');
            $historyStmt=$db->prepare('SELECT COUNT(*) FROM shop_order_items WHERE variant_id=?');
            $historyStmt->execute([$variantId]);
            if ((int)$historyStmt->fetchColumn()>0) throw new RuntimeException('This variant has order history. Deactivate it instead of deleting it.');
            $db->prepare('DELETE FROM shop_shipping_profiles WHERE variant_id=?')->execute([$variantId]);
            $db->prepare('DELETE FROM shop_product_variants WHERE id=? AND product_id=? LIMIT 1')->execute([$variantId,$productId]);
            shop_editor_redirect($productId,'Variant deleted.');
        }

        if ($action === 'save_variants') {
            $submitted=$_POST['variants']??[];
            if (!is_array($submitted)) throw new InvalidArgumentException('Variant data is invalid.');
            $variantLookup=$db->prepare('SELECT * FROM shop_product_variants WHERE id=? AND product_id=? LIMIT 1');
            $skuLookup=$db->prepare('SELECT id FROM shop_product_variants WHERE sku=? AND id<>? LIMIT 1');
            $update=$db->prepare('UPDATE shop_product_variants SET sku=?,name=?,price_cents=?,compare_at_price_cents=?,currency=?,track_inventory=?,inventory_quantity=?,allow_backorder=?,fulfillment_type=?,fulfillment_provider=?,fulfillment_product_id=?,fulfillment_variant_id=?,stripe_product_id=?,stripe_price_id=?,is_active=?,sort_order=? WHERE id=? AND product_id=? LIMIT 1');
            foreach ($submitted as $variantIdString=>$values) {
                $variantId=(int)$variantIdString;
                if ($variantId<1||!is_array($values)) continue;
                $variantLookup->execute([$variantId,$productId]);
                $current=$variantLookup->fetch(PDO::FETCH_ASSOC);
                if (!$current) continue;
                $sku=trim((string)($values['sku']??''));
                $variantName=trim((string)($values['name']??$current['name']??'Standard'));
                if ($sku===''||$variantName==='') throw new InvalidArgumentException('Every variant needs a name and SKU.');
                $skuLookup->execute([$sku,$variantId]);
                if ($skuLookup->fetchColumn()) throw new InvalidArgumentException('Duplicate SKU: '.$sku);
                $price=shop_editor_money_to_cents($values['price']??'');
                $compareAt=shop_editor_optional_money_to_cents($values['compare_at_price']??'');
                if ($compareAt!==null&&$compareAt<$price) throw new InvalidArgumentException('Compare price cannot be lower than the selling price for '.$sku.'.');
                $currency=strtolower(trim((string)($values['currency']??'usd')));
                $trackInventory=!empty($values['track_inventory']);
                $inventory=max(0,(int)($values['inventory_quantity']??0));
                $allowBackorder=!empty($values['allow_backorder']);
                $fulfillmentType=trim((string)($values['fulfillment_type']??LLAMA_SHOP_FULFILLMENT_MANUAL));
                if (!in_array($fulfillmentType,[LLAMA_SHOP_FULFILLMENT_MANUAL,LLAMA_SHOP_FULFILLMENT_PRINTFUL,LLAMA_SHOP_FULFILLMENT_PRINTIFY,LLAMA_SHOP_FULFILLMENT_EXTERNAL],true)) throw new InvalidArgumentException('Invalid fulfillment type for '.$sku.'.');
                $fulfillmentProvider=trim((string)($values['fulfillment_provider']??''));
                $fulfillmentProductId=trim((string)($values['fulfillment_product_id']??''));
                $fulfillmentVariantId=trim((string)($values['fulfillment_variant_id']??''));
                $stripeProductId=trim((string)($values['stripe_product_id']??''));
                $stripePriceId=trim((string)($values['stripe_price_id']??''));
                $active=!empty($values['is_active']);
                $variantSort=(int)($values['sort_order']??0);
                $update->execute([$sku,$variantName,$price,$compareAt,$currency,$trackInventory?1:0,$inventory,$allowBackorder?1:0,$fulfillmentType,$fulfillmentProvider!==''?$fulfillmentProvider:null,$fulfillmentProductId!==''?$fulfillmentProductId:null,$fulfillmentVariantId!==''?$fulfillmentVariantId:null,$stripeProductId!==''?$stripeProductId:null,$stripePriceId!==''?$stripePriceId:null,$active?1:0,$variantSort,$variantId,$productId]);
                if ($requiresShipping) {
                    $flatRate=shop_editor_optional_money_to_cents($values['flat_rate']??'');
                    $handling=shop_editor_optional_money_to_cents($values['handling']??'')??0;
                    llama_shipping_save_profile($db,$variantId,[
                        'shipping_strategy'=>trim((string)($values['shipping_strategy']??LLAMA_SHIPPING_PROVIDER_MANAGED)),
                        'carrier'=>trim((string)($values['carrier']??'')),
                        'preferred_service'=>trim((string)($values['preferred_service']??'')),
                        'package_type'=>trim((string)($values['package_type']??'custom_package')),
                        'weight_oz'=>$values['weight_oz']??'',
                        'length_in'=>$values['length_in']??'',
                        'width_in'=>$values['width_in']??'',
                        'height_in'=>$values['height_in']??'',
                        'girth_in'=>$values['girth_in']??'',
                        'ships_separately'=>!empty($values['ships_separately']),
                        'flat_rate_cents'=>$flatRate,
                        'handling_cents'=>$handling,
                        'origin_key'=>trim((string)($values['origin_key']??'default')),
                        'is_active'=>true,
                    ]);
                }
            }
            shop_editor_redirect($productId,'Variant pricing, inventory, fulfillment, and shipping saved.');
        }

        throw new InvalidArgumentException('Unknown shop action.');
    } catch (Throwable $exception) {
        $error=$exception->getMessage();
    }
}

if ($productId>0) {
    $stmt=$db->prepare('SELECT * FROM shop_products WHERE id=? LIMIT 1');
    $stmt->execute([$productId]);
    $product=$stmt->fetch(PDO::FETCH_ASSOC);
    if ($product) {
        $isEditing=true;
        $name=(string)$product['name'];
        $slug=(string)$product['slug'];
        $shortDescription=(string)($product['short_description']??'');
        $description=(string)($product['description']??'');
        $productType=(string)($product['product_type']??'');
        $status=(string)$product['status'];
        $isFeatured=(bool)$product['is_featured'];
        $requiresShipping=(bool)$product['requires_shipping'];
        $sortOrder=(int)$product['sort_order'];
    }
}

$productOptions=$isEditing?llama_shop_product_options($db,$productId):[];
$productImages=$isEditing?llama_shop_product_images($db,$productId):[];
$editorOptions=[];
foreach ($productOptions as $option) {
    $values=[];
    foreach (($option['values']??[]) as $value) {
        if (is_array($value)) $value=$value['option_value']??'';
        $value=trim((string)$value);
        if ($value!=='') $values[]=$value;
    }
    $editorOptions[]=['name'=>(string)$option['option_name'],'values'=>$values];
}

$variants=[];
if ($isEditing) {
    $stmt=$db->prepare('SELECT * FROM shop_product_variants WHERE product_id=? ORDER BY sort_order ASC,id ASC');
    $stmt->execute([$productId]);
    $variants=$stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($variants as &$variant) $variant['_attribute_pairs']=llama_shop_variant_values($db,(int)$variant['id']);
    unset($variant);
}

$shippingProfiles=[];
foreach ($variants as $variant) {
    $variantId=(int)$variant['id'];
    $profile=llama_shipping_profile($db,$variantId);
    if (!$profile) $profile=llama_shipping_default_profile($variant);
    $shippingProfiles[$variantId]=$profile;
}

$photoAssociations=[];
foreach ($editorOptions as $option) foreach ($option['values'] as $value) $photoAssociations[]=['name'=>$option['name'],'value'=>$value];
$pageTitle=$isEditing?'Edit Product':'Add Product';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= shop_editor_e($pageTitle) ?> | Shop | Llama Scout</title>
<meta name="robots" content="noindex,nofollow">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
<link rel="stylesheet" href="https://llamascout.com/css/style.css">
<link rel="stylesheet" href="https://llamascout.com/css/admin.css">
<style>
.shop-editor-section{margin-bottom:28px}.shop-editor-help{margin:7px 0 0;font-size:.84rem;line-height:1.5;opacity:.7}.shop-editor-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}.shop-editor-full{grid-column:1/-1}.shop-editor-check{display:flex;gap:9px;align-items:flex-start}.shop-editor-check input{margin-top:3px}.shop-attribute-list{display:grid;gap:10px;margin-top:18px;width:100%;max-width:100%}.shop-attribute-row{display:grid;grid-template-columns:minmax(150px,220px) minmax(220px,420px) auto;gap:10px;align-items:end;width:100%;max-width:100%;box-sizing:border-box;padding:12px;border:1px solid var(--border,rgba(127,127,127,.25));border-radius:14px;background:var(--surface,rgba(127,127,127,.04))}.shop-attribute-row .admin-field{min-width:0;margin:0}.shop-attribute-row select{width:100%;max-width:100%;min-height:42px}.shop-value-picker{position:relative;width:100%;max-width:100%}.shop-value-picker summary{position:relative;box-sizing:border-box;width:100%;max-width:100%;min-height:42px;padding:9px 34px 9px 11px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;border:1px solid var(--border,rgba(127,127,127,.3));border-radius:9px;cursor:pointer;list-style:none;background:var(--background,transparent)}.shop-value-picker summary::-webkit-details-marker{display:none}.shop-value-picker summary::after{content:"\25BE";position:absolute;top:50%;right:12px;transform:translateY(-50%)}.shop-value-menu{position:absolute;z-index:30;box-sizing:border-box;width:100%;min-width:220px;max-width:100%;max-height:300px;overflow-y:auto;overflow-x:hidden;margin-top:5px;padding:6px 8px;border:1px solid var(--border,rgba(127,127,127,.35));border-radius:10px;background:var(--background,#111);box-shadow:0 14px 36px rgba(0,0,0,.3)}.shop-value-choice{display:flex;align-items:center;gap:8px;width:100%;margin:0;padding:6px 4px;border-radius:7px;cursor:pointer;line-height:1.2}.shop-value-choice input[type=checkbox]{flex:0 0 auto;width:18px;height:18px;min-width:18px;margin:0;padding:0}.shop-value-choice span{display:inline;white-space:nowrap}.shop-attribute-actions{display:flex;align-items:center;gap:6px;min-width:94px}.shop-attribute-button{display:inline-flex;align-items:center;justify-content:center;flex:0 0 42px;width:42px;height:42px;min-width:42px;min-height:42px;padding:0;font-size:1.25rem}.shop-photo-upload{padding:18px;border:1px dashed var(--border,rgba(127,127,127,.4));border-radius:14px}.shop-photo-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;margin-top:18px}.shop-photo-card{overflow:hidden;border:1px solid var(--border,rgba(127,127,127,.25));border-radius:14px;background:var(--surface,rgba(127,127,127,.04))}.shop-photo-image{position:relative;aspect-ratio:1/1;background:rgba(127,127,127,.08)}.shop-photo-image img{display:block;width:100%;height:100%;object-fit:cover}.shop-photo-primary{position:absolute;top:8px;left:8px;padding:5px 8px;border-radius:999px;background:rgba(0,0,0,.78);color:#fff;font-size:.7rem;font-weight:800}.shop-photo-info{padding:13px}.shop-photo-assignment{display:inline-block;margin-bottom:10px;padding:5px 8px;border-radius:999px;font-size:.76rem;font-weight:800;background:rgba(127,127,127,.12)}.shop-photo-card .admin-field{margin-bottom:11px}.shop-photo-card select,.shop-photo-card input[type=text]{width:100%}.shop-variant-table{min-width:980px}.shop-variant-name{min-width:190px}.shop-variant-name small{display:block;margin-top:7px;line-height:1.4;opacity:.72}.shop-variant-price{width:105px}.shop-variant-stock{width:90px}.shop-variant-advanced{margin-top:10px}.shop-variant-advanced summary{cursor:pointer;font-weight:800}.shop-variant-panel{margin-top:12px;padding:14px;border:1px solid var(--border,rgba(127,127,127,.2));border-radius:12px;background:var(--surface,rgba(127,127,127,.04))}.shop-shipping-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.shop-action-bar{position:sticky;bottom:12px;z-index:5;display:flex;justify-content:flex-end;margin-top:18px;pointer-events:none}.shop-action-bar>*{pointer-events:auto;box-shadow:0 8px 30px rgba(0,0,0,.16)}.shop-delete-button{border-color:rgba(185,70,70,.7)!important}.shop-editor-callout{padding:16px;border:1px solid var(--border,rgba(127,127,127,.28));border-radius:14px;background:var(--surface,rgba(127,127,127,.06))}@media(max-width:900px){.shop-editor-grid,.shop-shipping-grid{grid-template-columns:1fr}.shop-photo-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.shop-attribute-row{grid-template-columns:minmax(130px,190px) minmax(180px,1fr) 94px}}@media(max-width:640px){.shop-photo-grid{grid-template-columns:1fr}.shop-attribute-row{grid-template-columns:minmax(0,1fr) 88px;gap:8px;padding:10px}.shop-attribute-row .admin-field:first-child{grid-column:1/2}.shop-attribute-row .admin-field:nth-child(2){grid-column:1/-1}.shop-attribute-actions{grid-column:2/3;grid-row:1;align-self:end;min-width:88px}.shop-attribute-button{flex-basis:40px;width:40px;height:40px;min-width:40px;min-height:40px}.shop-value-menu{min-width:100%}}
</style>
</head>
<body class="admin-page">
<?php require_once dirname(__DIR__) . '/app/header.php'; ?>
<main class="admin-main">
<section class="admin-intro"><div class="admin-intro-row"><div class="admin-intro-copy"><p class="admin-eyebrow"><i class="<?= shop_editor_e($primaryRoleIcon) ?>" aria-hidden="true"></i> Shop Management</p><h1><?= shop_editor_e($pageTitle) ?></h1><p>Build the product in order: product details, variants, photos, then pricing and inventory.</p></div><div class="admin-intro-actions"><a class="admin-button admin-button--secondary" href="/shop.php"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Shop Dashboard</a><?php if($isEditing&&$status===LLAMA_SHOP_PRODUCT_ACTIVE): ?><a class="admin-button admin-button--secondary" href="https://llamascout.com/product.php?slug=<?= rawurlencode($slug) ?>" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i> View Product</a><?php endif; ?></div></div></section>
<?php require dirname(__DIR__) . '/app/admin-nav.php'; ?>
<?php if($notice!==''): ?><div class="admin-alert admin-alert--success"><?= shop_editor_e($notice) ?></div><?php endif; ?>
<?php if($error!==''): ?><div class="admin-alert admin-alert--error"><?= shop_editor_e($error) ?></div><?php endif; ?>

<section class="admin-section shop-editor-section"><div class="admin-section-header"><div><h2>1. Product</h2><p>Basic information shared by every version of this product.</p></div></div><form method="post" action="<?= $isEditing?'/shop-product.php?id='.$productId:'/shop-product.php' ?>" class="admin-form"><input type="hidden" name="csrf_token" value="<?= shop_editor_e($csrfToken) ?>"><input type="hidden" name="action" value="save_product"><input type="hidden" name="product_id" value="<?= $productId ?>"><div class="shop-editor-grid"><div class="admin-field"><label for="name">Product Name</label><input id="name" name="name" type="text" maxlength="200" required value="<?= shop_editor_e($name) ?>" placeholder="Llama Scout Logo Tee"></div><div class="admin-field"><label for="product_type">Category / Product Type</label><input id="product_type" name="product_type" type="text" maxlength="60" value="<?= shop_editor_e($productType) ?>" placeholder="Apparel"><p class="shop-editor-help">Examples: Apparel, Headwear, Stickers, Trail Gear, Sensory Camp.</p></div><div class="admin-field"><label for="slug">URL Slug</label><input id="slug" name="slug" type="text" maxlength="160" value="<?= shop_editor_e($slug) ?>"></div><div class="admin-field"><label for="status">Storefront Status</label><select id="status" name="status"><option value="draft" <?= $status===LLAMA_SHOP_PRODUCT_DRAFT?'selected':'' ?>>Draft</option><option value="active" <?= $status===LLAMA_SHOP_PRODUCT_ACTIVE?'selected':'' ?>>Active</option><option value="archived" <?= $status===LLAMA_SHOP_PRODUCT_ARCHIVED?'selected':'' ?>>Archived</option></select></div><div class="admin-field shop-editor-full"><label for="short_description">Short Description</label><input id="short_description" name="short_description" type="text" maxlength="500" value="<?= shop_editor_e($shortDescription) ?>"></div><div class="admin-field shop-editor-full"><label for="description">Full Description</label><textarea id="description" name="description" rows="8"><?= shop_editor_e($description) ?></textarea></div><div class="admin-field"><label for="sort_order">Sort Order</label><input id="sort_order" name="sort_order" type="number" step="1" value="<?= $sortOrder ?>"></div><div class="admin-field"><label class="shop-editor-check"><input type="checkbox" name="is_featured" value="1" <?= $isFeatured?'checked':'' ?>><span><strong>Featured product</strong><br><small>Give this product priority on the Shop homepage.</small></span></label></div><div class="admin-field"><label class="shop-editor-check"><input type="checkbox" name="requires_shipping" value="1" <?= $requiresShipping?'checked':'' ?>><span><strong>Physical product</strong><br><small>Requires shipping to the customer.</small></span></label></div></div><div class="admin-form-actions"><button class="admin-button" type="submit"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> <?= $isEditing?'Save Product':'Create Product' ?></button></div></form></section>

<?php if($isEditing): ?>
<section class="admin-section shop-editor-section"><div class="admin-section-header"><div><h2>2. Variant Builder</h2><p>Choose what can vary, then select the values you actually sell. Llama Scout creates the combinations automatically.</p></div></div><form method="post" action="/shop-product.php?id=<?= $productId ?>" class="admin-form" data-options-form><input type="hidden" name="csrf_token" value="<?= shop_editor_e($csrfToken) ?>"><input type="hidden" name="action" value="save_options"><input type="hidden" name="product_id" value="<?= $productId ?>"><label class="shop-editor-check"><input type="checkbox" name="has_options" value="1" data-has-options <?= $editorOptions?'checked':'' ?>><span><strong>This product has choices</strong><br><small>Examples: sex, size, color, pattern, and length.</small></span></label><div data-option-controls <?= !$editorOptions?'hidden':'' ?>><div class="shop-attribute-list" data-attribute-list><?php $rowsToRender=$editorOptions?:[['name'=>'','values'=>[]]]; foreach($rowsToRender as $rowIndex=>$editorOption): $selectedName=(string)($editorOption['name']??''); $selectedValues=$editorOption['values']??[]; $availableValues=$selectedName!==''?llama_shop_variant_attribute_values($selectedName):[]; ?><div class="shop-attribute-row" data-attribute-row data-index="<?= (int)$rowIndex ?>"><div class="admin-field"><label>Attribute</label><select name="attributes[<?= $rowIndex ?>][name]" data-attribute-name required><option value="">Choose attribute</option><?php foreach(llama_shop_variant_attribute_names() as $attributeName): ?><option value="<?= shop_editor_e($attributeName) ?>" <?= $selectedName===$attributeName?'selected':'' ?>><?= shop_editor_e($attributeName) ?></option><?php endforeach; ?></select></div><div class="admin-field"><label>Values</label><details class="shop-value-picker" data-value-picker><summary data-value-summary><?= $selectedValues?shop_editor_e(implode(', ',$selectedValues)):'Choose values' ?></summary><div class="shop-value-menu" data-value-menu><?php foreach($availableValues as $value): ?><label class="shop-value-choice"><input type="checkbox" name="attributes[<?= $rowIndex ?>][values][]" value="<?= shop_editor_e($value) ?>" <?= in_array($value,$selectedValues,true)?'checked':'' ?>><span><?= shop_editor_e($value) ?></span></label><?php endforeach; ?></div></details></div><div class="shop-attribute-actions"><button class="admin-button admin-button--secondary shop-attribute-button" type="button" data-remove-attribute aria-label="Remove attribute">&minus;</button><button class="admin-button shop-attribute-button" type="button" data-add-attribute aria-label="Add another attribute">&plus;</button></div></div><?php endforeach; ?></div><div class="shop-editor-grid" style="margin-top:18px"><div class="admin-field"><label for="default_price">Starting Price</label><input id="default_price" name="default_price" type="number" min="0" step="0.01" value="<?= $variants?shop_editor_e(shop_editor_money_input((int)$variants[0]['price_cents'])):'0.00' ?>"></div><div class="admin-field"><label for="sku_prefix">SKU Prefix</label><input id="sku_prefix" name="sku_prefix" type="text" value="<?= shop_editor_e(shop_editor_sku_piece($slug)) ?>"><p class="shop-editor-help">Variant values are appended automatically.</p></div></div></div><div class="admin-form-actions"><button class="admin-button" type="submit"><i class="fa-solid fa-table-cells" aria-hidden="true"></i> Save & Build Variants</button></div></form></section>

<section class="admin-section shop-editor-section"><div class="admin-section-header"><div><h2>3. Photos</h2><p>Upload first. Then use the controls under each image to say what that photo represents.</p></div></div><div class="shop-photo-upload"><form method="post" enctype="multipart/form-data" action="/shop-product.php?id=<?= $productId ?>" class="admin-form"><input type="hidden" name="csrf_token" value="<?= shop_editor_e($csrfToken) ?>"><input type="hidden" name="action" value="upload_photos"><input type="hidden" name="product_id" value="<?= $productId ?>"><div class="admin-field"><label for="product_photos">Add Product Photos</label><input id="product_photos" name="product_photos[]" type="file" accept="image/*" multiple required><p class="shop-editor-help">Upload up to 20 at once. They start as Entire product. Assign each image below after upload.</p></div><div class="admin-form-actions"><button class="admin-button" type="submit"><i class="fa-solid fa-images" aria-hidden="true"></i> Upload Photos</button></div></form></div><?php if($productImages): ?><div class="shop-photo-grid"><?php foreach($productImages as $image): $imageId=(int)$image['id']; $currentAssignment=''; if(!empty($image['option_name'])&&!empty($image['option_value'])) $currentAssignment=json_encode(['name'=>(string)$image['option_name'],'value'=>(string)$image['option_value']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); ?><article class="shop-photo-card"><div class="shop-photo-image"><img
  src="<?= shop_editor_e(
      str_starts_with(
          (string) $image['image_url'],
          '/'
      )
          ? 'https://llamascout.com'
            . $image['image_url']
          : $image['image_url']
  ) ?>"
  alt="<?= shop_editor_e(
      $image['alt_text']
      ?: $name
  ) ?>"
><?php if((bool)$image['is_primary']): ?><span class="shop-photo-primary">Primary</span><?php endif; ?></div><div class="shop-photo-info"><span class="shop-photo-assignment"><?= shop_editor_e(shop_editor_photo_assignment_label($image['option_name']??null,$image['option_value']??null)) ?></span><form method="post" action="/shop-product.php?id=<?= $productId ?>" class="admin-form"><input type="hidden" name="csrf_token" value="<?= shop_editor_e($csrfToken) ?>"><input type="hidden" name="action" value="save_photo"><input type="hidden" name="product_id" value="<?= $productId ?>"><input type="hidden" name="image_id" value="<?= $imageId ?>"><div class="admin-field"><label>This photo shows</label><select name="photo_assignment"><option value="">Entire product / all variants</option><?php foreach($photoAssociations as $association): $assignmentJson=json_encode(['name'=>$association['name'],'value'=>$association['value']],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); ?><option value="<?= shop_editor_e($assignmentJson) ?>" <?= $currentAssignment===$assignmentJson?'selected':'' ?>><?= shop_editor_e($association['name'].': '.$association['value']) ?></option><?php endforeach; ?></select><p class="shop-editor-help">Example: Color: Black applies this image to every Black size.</p></div><div class="admin-field"><label>Alt Text</label><input type="text" name="alt_text" maxlength="300" value="<?= shop_editor_e($image['alt_text']??'') ?>"></div><?php if(!(bool)$image['is_primary']): ?><label class="shop-editor-check"><input type="checkbox" name="make_primary" value="1"><span>Make primary product photo</span></label><?php endif; ?><div class="admin-form-actions"><button class="admin-button" type="submit">Save Photo</button></div></form><form method="post" action="/shop-product.php?id=<?= $productId ?>" onsubmit="return confirm('Delete this product photo?');"><input type="hidden" name="csrf_token" value="<?= shop_editor_e($csrfToken) ?>"><input type="hidden" name="action" value="delete_image"><input type="hidden" name="product_id" value="<?= $productId ?>"><input type="hidden" name="image_id" value="<?= $imageId ?>"><button class="admin-button admin-button--secondary shop-delete-button" type="submit"><i class="fa-solid fa-trash" aria-hidden="true"></i> Delete Photo</button></form></div></article><?php endforeach; ?></div><?php else: ?><div class="shop-editor-callout" style="margin-top:18px">No product photos uploaded yet.</div><?php endif; ?></section>

<section class="admin-section shop-editor-section"><div class="admin-section-header"><div><h2>4. Variants</h2><p><?= count($variants) ?> sellable <?= count($variants)===1?'variant':'variants' ?>. Set each SKU's price, inventory, availability, fulfillment, and shipping.</p></div></div><?php if(!$variants): ?><div class="shop-editor-callout"><strong>Build variants first.</strong></div><?php else: ?><form method="post" action="/shop-product.php?id=<?= $productId ?>" class="admin-form"><input type="hidden" name="csrf_token" value="<?= shop_editor_e($csrfToken) ?>"><input type="hidden" name="action" value="save_variants"><input type="hidden" name="product_id" value="<?= $productId ?>"><div class="admin-table-wrap"><table class="admin-table shop-variant-table"><thead><tr><th>Variant</th><th>SKU</th><th>Price</th><th>Compare</th><th>Inventory</th><th>Available</th></tr></thead><tbody><?php foreach($variants as $variant): $variantId=(int)$variant['id']; $pairs=shop_editor_variant_pairs($variant); $shipping=$shippingProfiles[$variantId]??llama_shipping_default_profile($variant); ?><tr><td class="shop-variant-name"><input type="hidden" name="variants[<?= $variantId ?>][sort_order]" value="<?= (int)$variant['sort_order'] ?>"><input type="text" name="variants[<?= $variantId ?>][name]" value="<?= shop_editor_e($variant['name']) ?>" required><?php if($pairs): ?><small><?php foreach($pairs as $index=>$pair): ?><?= $index>0?' &middot; ':'' ?><?= shop_editor_e($pair['name']) ?>: <?= shop_editor_e($pair['value']) ?><?php endforeach; ?></small><?php endif; ?><details class="shop-variant-advanced"><summary>Fulfillment & Shipping</summary><div class="shop-variant-panel"><div class="shop-editor-grid"><div class="admin-field"><label>Fulfillment</label><select name="variants[<?= $variantId ?>][fulfillment_type]"><option value="manual" <?= $variant['fulfillment_type']==='manual'?'selected':'' ?>>Llama Scout / In-house</option><option value="printful" <?= $variant['fulfillment_type']==='printful'?'selected':'' ?>>Printful</option><option value="printify" <?= $variant['fulfillment_type']==='printify'?'selected':'' ?>>Printify</option><option value="external" <?= $variant['fulfillment_type']==='external'?'selected':'' ?>>Other / External</option></select></div><div class="admin-field"><label>Provider Name</label><input type="text" name="variants[<?= $variantId ?>][fulfillment_provider]" value="<?= shop_editor_e($variant['fulfillment_provider']??'') ?>"></div><div class="admin-field"><label>Provider Product ID</label><input type="text" name="variants[<?= $variantId ?>][fulfillment_product_id]" value="<?= shop_editor_e($variant['fulfillment_product_id']??'') ?>"></div><div class="admin-field"><label>Provider Variant ID</label><input type="text" name="variants[<?= $variantId ?>][fulfillment_variant_id]" value="<?= shop_editor_e($variant['fulfillment_variant_id']??'') ?>"></div></div><?php if($requiresShipping): ?><hr><h4>Shipping & Package</h4><div class="shop-shipping-grid"><div class="admin-field"><label>Shipping Method</label><select name="variants[<?= $variantId ?>][shipping_strategy]"><option value="provider_managed" <?= ($shipping['shipping_strategy']??'')==='provider_managed'?'selected':'' ?>>Provider Handles It</option><option value="live_rates" <?= ($shipping['shipping_strategy']??'')==='live_rates'?'selected':'' ?>>Live Carrier Rates</option><option value="flat_rate" <?= ($shipping['shipping_strategy']??'')==='flat_rate'?'selected':'' ?>>Flat Rate</option><option value="free" <?= ($shipping['shipping_strategy']??'')==='free'?'selected':'' ?>>Free Shipping</option></select></div><div class="admin-field"><label>Carrier</label><select name="variants[<?= $variantId ?>][carrier]"><option value="">Automatic / None</option><option value="usps" <?= ($shipping['carrier']??'')==='usps'?'selected':'' ?>>USPS</option><option value="ups" <?= ($shipping['carrier']??'')==='ups'?'selected':'' ?>>UPS</option><option value="fedex" <?= ($shipping['carrier']??'')==='fedex'?'selected':'' ?>>FedEx</option></select></div><div class="admin-field"><label>Preferred Service</label><input type="text" name="variants[<?= $variantId ?>][preferred_service]" value="<?= shop_editor_e($shipping['preferred_service']??'') ?>"></div><div class="admin-field"><label>Package Type</label><input type="text" name="variants[<?= $variantId ?>][package_type]" value="<?= shop_editor_e($shipping['package_type']??'custom_package') ?>"></div><div class="admin-field"><label>Weight (oz)</label><input type="number" min="0" step="0.01" name="variants[<?= $variantId ?>][weight_oz]" value="<?= shop_editor_e($shipping['weight_oz']??'') ?>"></div><div class="admin-field"><label>Length (in)</label><input type="number" min="0" step="0.01" name="variants[<?= $variantId ?>][length_in]" value="<?= shop_editor_e($shipping['length_in']??'') ?>"></div><div class="admin-field"><label>Width (in)</label><input type="number" min="0" step="0.01" name="variants[<?= $variantId ?>][width_in]" value="<?= shop_editor_e($shipping['width_in']??'') ?>"></div><div class="admin-field"><label>Height (in)</label><input type="number" min="0" step="0.01" name="variants[<?= $variantId ?>][height_in]" value="<?= shop_editor_e($shipping['height_in']??'') ?>"></div><div class="admin-field"><label>Girth (in)</label><input type="number" min="0" step="0.01" name="variants[<?= $variantId ?>][girth_in]" value="<?= shop_editor_e($shipping['girth_in']??'') ?>"></div><div class="admin-field"><label>Flat Rate</label><input type="number" min="0" step="0.01" name="variants[<?= $variantId ?>][flat_rate]" value="<?= shop_editor_e(shop_editor_money_input(isset($shipping['flat_rate_cents'])&&$shipping['flat_rate_cents']!==null?(int)$shipping['flat_rate_cents']:null)) ?>"></div><div class="admin-field"><label>Handling</label><input type="number" min="0" step="0.01" name="variants[<?= $variantId ?>][handling]" value="<?= shop_editor_e(shop_editor_money_input(isset($shipping['handling_cents'])?(int)$shipping['handling_cents']:0)) ?>"></div><div class="admin-field"><label>Origin Key</label><input type="text" name="variants[<?= $variantId ?>][origin_key]" value="<?= shop_editor_e($shipping['origin_key']??'default') ?>"></div><div class="admin-field"><label class="shop-editor-check"><input type="checkbox" name="variants[<?= $variantId ?>][ships_separately]" value="1" <?= !empty($shipping['ships_separately'])?'checked':'' ?>><span>Ships separately</span></label></div></div><?php endif; ?></div></details></td><td><input type="text" name="variants[<?= $variantId ?>][sku]" value="<?= shop_editor_e($variant['sku']) ?>" required></td><td><input class="shop-variant-price" type="number" min="0" step="0.01" name="variants[<?= $variantId ?>][price]" value="<?= shop_editor_e(shop_editor_money_input((int)$variant['price_cents'])) ?>" required><input type="hidden" name="variants[<?= $variantId ?>][currency]" value="<?= shop_editor_e($variant['currency']) ?>"><input type="hidden" name="variants[<?= $variantId ?>][stripe_product_id]" value="<?= shop_editor_e($variant['stripe_product_id']??'') ?>"><input type="hidden" name="variants[<?= $variantId ?>][stripe_price_id]" value="<?= shop_editor_e($variant['stripe_price_id']??'') ?>"></td><td><input class="shop-variant-price" type="number" min="0" step="0.01" name="variants[<?= $variantId ?>][compare_at_price]" value="<?= shop_editor_e(shop_editor_money_input($variant['compare_at_price_cents']!==null?(int)$variant['compare_at_price_cents']:null)) ?>" placeholder="Optional"></td><td><label class="shop-editor-check"><input type="checkbox" name="variants[<?= $variantId ?>][track_inventory]" value="1" <?= (bool)$variant['track_inventory']?'checked':'' ?>><span>Track</span></label><input class="shop-variant-stock" type="number" min="0" step="1" name="variants[<?= $variantId ?>][inventory_quantity]" value="<?= (int)$variant['inventory_quantity'] ?>"><label class="shop-editor-check"><input type="checkbox" name="variants[<?= $variantId ?>][allow_backorder]" value="1" <?= (bool)$variant['allow_backorder']?'checked':'' ?>><span>Backorder</span></label></td><td><label class="shop-editor-check"><input type="checkbox" name="variants[<?= $variantId ?>][is_active]" value="1" <?= (bool)$variant['is_active']?'checked':'' ?>><span>Active</span></label><button class="admin-button admin-button--secondary shop-delete-button" type="submit" onclick="this.form.querySelector('[name=action]').value='delete_variant';let input=this.form.querySelector('[name=variant_id]');if(!input){input=document.createElement('input');input.type='hidden';input.name='variant_id';this.form.appendChild(input)}input.value='<?= $variantId ?>';return confirm('Delete this variant? This cannot be undone.');"><i class="fa-solid fa-trash" aria-hidden="true"></i> Delete</button></td></tr><?php endforeach; ?></tbody></table></div><div class="shop-action-bar"><button class="admin-button" type="submit"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Save All Variants</button></div></form><?php endif; ?></section>
<?php endif; ?>
</main>
<?php require_once dirname(__DIR__) . '/app/footer.php'; ?>
<script src="https://llamascout.com/js/header.js"></script>
<script>
(()=>{const definitions=<?= json_encode(llama_shop_variant_attribute_definitions(),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>;const toggle=document.querySelector('[data-has-options]');const controls=document.querySelector('[data-option-controls]');const list=document.querySelector('[data-attribute-list]');if(!toggle||!controls||!list)return;let nextIndex=list.querySelectorAll('[data-attribute-row]').length;function esc(v){const d=document.createElement('div');d.textContent=v;return d.innerHTML}function updateToggle(){controls.hidden=!toggle.checked}function updateSummary(row){const checked=[...row.querySelectorAll('[data-value-menu] input:checked')];const summary=row.querySelector('[data-value-summary]');if(summary)summary.textContent=checked.length?checked.map(i=>i.value).join(', '):'Choose values'}function rebuildValues(row){const select=row.querySelector('[data-attribute-name]');const menu=row.querySelector('[data-value-menu]');if(!select||!menu)return;const index=row.dataset.index;const values=definitions[select.value]||[];menu.innerHTML=values.map(value=>`<label class="shop-value-choice"><input type="checkbox" name="attributes[${index}][values][]" value="${esc(value)}"><span>${esc(value)}</span></label>`).join('');updateSummary(row)}function updateAvailable(){const rows=[...list.querySelectorAll('[data-attribute-row]')];const selected=rows.map(row=>row.querySelector('[data-attribute-name]')?.value).filter(Boolean);rows.forEach(row=>{const select=row.querySelector('[data-attribute-name]');if(!select)return;const own=select.value;[...select.options].forEach(option=>{option.disabled=option.value!==''&&option.value!==own&&selected.includes(option.value)})})}function wire(row){const select=row.querySelector('[data-attribute-name]');if(select)select.addEventListener('change',()=>{rebuildValues(row);updateAvailable()});row.addEventListener('change',e=>{if(e.target.matches('[data-value-menu] input'))updateSummary(row)});row.querySelector('[data-add-attribute]')?.addEventListener('click',addRow);row.querySelector('[data-remove-attribute]')?.addEventListener('click',()=>{const rows=list.querySelectorAll('[data-attribute-row]');if(rows.length===1){select.value='';rebuildValues(row);updateAvailable();return}row.remove();updateAvailable()})}function addRow(){const available=Object.keys(definitions);const current=[...list.querySelectorAll('[data-attribute-name]')].map(s=>s.value).filter(Boolean);const remaining=available.filter(name=>!current.includes(name));if(!remaining.length)return;const index=nextIndex++;const row=document.createElement('div');row.className='shop-attribute-row';row.dataset.attributeRow='';row.dataset.index=String(index);row.innerHTML=`<div class="admin-field"><label>Attribute</label><select name="attributes[${index}][name]" data-attribute-name required><option value="">Choose attribute</option>${available.map(name=>`<option value="${esc(name)}">${esc(name)}</option>`).join('')}</select></div><div class="admin-field"><label>Values</label><details class="shop-value-picker" data-value-picker><summary data-value-summary>Choose values</summary><div class="shop-value-menu" data-value-menu></div></details></div><div class="shop-attribute-actions"><button class="admin-button admin-button--secondary shop-attribute-button" type="button" data-remove-attribute aria-label="Remove attribute">&minus;</button><button class="admin-button shop-attribute-button" type="button" data-add-attribute aria-label="Add another attribute">&plus;</button></div>`;list.appendChild(row);wire(row);const select=row.querySelector('[data-attribute-name]');select.value=remaining[0];rebuildValues(row);updateAvailable()}[...list.querySelectorAll('[data-attribute-row]')].forEach((row,index)=>{row.dataset.index=String(index);wire(row);updateSummary(row)});toggle.addEventListener('change',updateToggle);updateToggle();updateAvailable()})();
</script>
</body>
</html>
