<?php

namespace App\Http\Controllers;

use App\Exceptions\ShippingFeeCalculationException;
use App\Services\ShippingService;
use App\Models\CartItem;
use App\Models\OrderItem;
use App\Models\Order;
use App\Models\Cart;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Exception;

class CheckOutController extends Controller
{
    private const REQUIRED_STRING_RULE = 'required|string';
    private $shippingService;

    public function __construct(ShippingService $shippingService){
        $this->shippingService = $shippingService;
    }

    public function getCartItems()
    {
        $user = Auth::user();
        if (!$user instanceof \App\Models\User) {
            abort(401);
        }
        $cartController = new CartController();
        $cartData = $cartController->getCartItems($user);

        $allItemsTypeOne = $cartData['instockCartItems']->every(function($item){
            return $item->product->type == 1;
        });

        return view('cart.checkout', [
            'cartItems' => $cartData['instockCartItems'],
            'totalQuantity' => $cartData['totalQuantity'],
            'totalDiscountedPrice' => $cartData['totalDiscountedPrice'],
            'user' => $user,
            'allItemsTypeOne' => $allItemsTypeOne
        ]);
    }

    private function mappingLocationCodes($provinceName, $districtName, $wardName)
    {
        $data = json_decode(file_get_contents(storage_path('data/provinces.json')), true);
        
        // Find province ID safely
        $provinceId = null;
        foreach ($data as $id => $province) {
            if (strtolower($province['ProvinceName']) === strtolower($provinceName)) {
                $provinceId = $id;
                break;
            }
        }
        
        if (!$provinceId || !isset($data[$provinceId]['Districts'])) {
            Log::warning('Province not found', ['province' => $provinceName]);
            return null;
        }

        // Find district safely
        $districts = $data[$provinceId]['Districts'];
        $districtId = null;
        foreach ($districts as $id => $district) {
            if (strtolower($district['DistrictName']) === strtolower($districtName)) {
                $districtId = $id;
                break;
            }
        }

        if (!$districtId || !isset($districts[$districtId]['Wards'])) {
            Log::warning('District not found', ['district' => $districtName]);
        }

        // Find ward safely
        $wards = $districts[$districtId]['Wards'];
        $wardCode = null;
        foreach ($wards as $code => $ward) {
            if (strtolower($ward['WardName']) === strtolower($wardName)) {
                $wardCode = $code;
                break;
            }
        }

        if (!$wardCode) {
            Log::warning('Ward not found', ['ward' => $wardName]);
            return null;
        }

        return [
            'district_id' => $districtId,
            'ward_code' => $wardCode,
            'province_id' => $provinceId
        ];
    }

    // public function getInitialShippingFee()
    // {
    //     try {
    //         $user = Auth::user();

    //         if (!$user->province_city || !$user->district || !$user->commune_ward) {
    //             throw new ShippingFeeCalculationException('Missing location data');
    //         }

    //         Log::debug('Prepare for mapping location');

    //         $locationCodes = $this->mappingLocationCodes(
    //             $user->province_city,
    //             $user->district,
    //             $user->commune_ward
    //         );
            
    //         Log::debug('Location codes after mapping successfully: ', $locationCodes);
            
    //         if (!$locationCodes) {
    //             return response()->json([
    //                 'success' => false,
    //                 'message' => 'Could not map location to valid codes',
    //                 'data' => null
    //             ], 422);
    //         }

    //         Log::debug('Before calculate shipping fee');

    //         $response = $this->shippingService->calculateFee(
    //             $locationCodes['district_id'],
    //             $locationCodes['ward_code'],
    //             $user->cart_id
    //         );
            
    //         Log::debug('Initial shipping fee calculated:', ['fee' => $response['total']]);

    //         return response()->json([
    //             'success' => true,
    //             'message' => 'Shipping fee calculated successfully',
    //             'data' => [
    //                 'shipping_fee' => (int) $response['total'],
    //                 'location' => $locationCodes
    //             ]
    //         ]);

    //     } catch (Exception $e) {
    //         Log::error('Shipping fee calculation failed', [
    //             'error' => $e->getMessage(),
    //             'user_id' => $user->id
    //         ]);

    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Failed to calculate shipping fee',
    //             'data' => null
    //         ], 500);
    //     }
    // }

    public function calculatingShippingFee(Request $request){
        try {
            // Access nested data
            // $data = $request->input('to_district_id');
            Log::debug('See difference in address, calcuating new shipping fee');

            $districtId = $request['to_district_id'] ?? null;
            $wardCode = $request['to_ward_code'] ?? null;

            if (!$districtId || !$wardCode) {
                throw new ValidationException(validator([], []));
            }

            // Convert to integers where needed
            $districtId = (int) $districtId;
            $wardCode = (string) $wardCode;
            $cartId = Auth::user()->cart_id;

            Log::debug('Data input for shipping calculation', [
                'district_id' => $districtId,
                'ward_code' => $wardCode,
                'cart_id' => $cartId
            ]);

            $fee = $this->shippingService->calculateFee(
                $districtId,
                $wardCode,
                $cartId
            );

            Log::debug('Shipping fee calculated', [
                'fee' => $fee,
                'to_district_id' => $districtId,
                'to_ward_code' => $wardCode,
                'cart_id' => $cartId
            ]);

            return response()->json([
                'success' => true,
                'shipping_fee' => $fee
            ]);

        } catch (ShippingFeeCalculationException $e) {
            return response()->json([
                'success'=> false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'success'=> false,
                'message' => 'An error occurred while calculating shipping fee',
            ], 500);
        }
    }

    public function getUserInfo()
    {
        try {
            $user = Auth::user();
            Log::debug('Getting user info', ['user' => $user->user_id]);
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not authenticated'
                ], 401);
            }
            
            $locationCodes = $this->mappingLocationCodes(
                $user->province_city,
                $user->district,
                $user->commune_ward,
            );

            Log::debug('DistrictId: ', ['id'=> $locationCodes['district_id']]);
            Log::debug('Ward code: ', ['code' => $locationCodes['ward_code']]);

            return response()->json([
                'success' => true,
                'fullname' => $user->full_name,
                'phone' => $user->phone_number,
                'province' => $user->province_city,
                'district' => $user->district,
                'ward' => $user->commune_ward,
                'district_id' => $locationCodes['district_id'],
                'ward_code' => $locationCodes['ward_code'],
                'province_id' => $locationCodes['province_id']
            ]);

        } catch (Exception $e) {
            Log::error('Failed to load user info', [
                'error' => $e->getMessage(),
                'user_id' => Auth::id()
            ]);
            
            return response()->json([
                'success'=> false,
                'message' => 'An error occurred while getting user shipping info',
            ], 500);
        }
    }

    public function updateDefaultAddress(Request $request)
    {
        try{
            $validated = $request->validate([
                'address' => self::REQUIRED_STRING_RULE
            ]);

            Log::debug('Validated data', $validated);

            $userId = Auth()->user()->user_id;

            DB::table('users')
            ->where('user_id', $userId)
            ->update([
                'province_city' => request('province'),
                'district' => request('district'),
                'commune_ward' => request('ward'),
                'address' => $validated['address']
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Địa chỉ đã được cập nhật thành công'
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid data',
                'errors' => $e->errors()
            ], 422);
        } catch (Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to update address',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function submitOrder(Request $request)
    {
        DB::beginTransaction();
		try{
			$user = Auth::user();
			
            // Validate request
            $validated = $request->validate([
                'address' => 'required|array'
            ]);

			$cartItems = CartItem::with(['product'])
					->where('cart_id', $user->cart_id)
					->get();
            if ($cartItems->isEmpty()) {
                throw new Exception('Cart is empty');
            }

			// 1. Create new Order
            $order = $this->createNewOrder(
                $request->voucher_id ?? null,
                $request->provisional_price,
                $request->delivery_cost,
                $request->total_price,
                $validated['address'],
                $request->payment_method ?? 'COD',
                $request->additional_note ?? null
            );

			// 2. Transfer Cart items to Order items
			foreach ($cartItems as $item) {
                OrderItem::create([
                    'order_id' => $order->getAttribute('order_id'),
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'total_price' => $item->total_price,
                    'discounted_amount' => $item->discounted_amount ?? 0
                ]);

                // Update product stock quantity
                $product = Product::find($item->product_id);
                $product->decrement('stock_quantity', $item->quantity);
            }
            
			// 3. Clear Cart items and update Cart items_count
			CartItem::where('cart_id', $user->cart_id)->delete();
			Cart::where('cart_id', $user->cart_id)->update(['items_count' => 0]);
            DB::commit();

			return response()->json([
                'success' => true,
                'order_id' => $order->getAttribute('order_id'),
                'message' => 'Order created successfully'
            ]);
		}
		catch (Exception $e){
			DB::rollBack();
            Log::error('Order creation failed', [
                'error' => $e->getMessage(),
                'user_id' => $user->id ?? null
            ]);
            
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
	}
    private function isAddressChanged($newProvince, $newDistrict, $newCity)
    {
        $user = Auth::user();
        
        Log::debug('Comparing addresses', [
            'current' => [
                'province' => $user->province_city,
                'district' => $user->district,
                'city' => $user->commune_ward
            ],
            'new' => [
                'province' => $newProvince,
                'district' => $newDistrict,
                'city' => $newCity
            ]
        ]);
        return $user->province_city !== $newProvince ||
            $user->district !== $newDistrict ||
            $user->commune_ward !== $newCity;
    }

    private function createNewOrder(
        $voucherId,
        $provisionalPrice,
        $deliveryCost,
        $totalPrice,
        $address,
        $paymentMethod,
        $additionalNote)
    {
        try{
            if (!isset($address['province_city']) || !isset($address['district']) || !isset($address['commune_ward'])) {
                throw new Exception('Invalid address format');
            }
            
            $userId = Auth::id();
            
            $addressChanged = $this->isAddressChanged(
                $address['province_city'],
                $address['district'],
                $address['commune_ward']
            );

            Log::debug('Creating new order', [
                'address_changed' => $addressChanged,
                'delivery_cost' => $deliveryCost
            ]);

            $finalDeliveryCost = $addressChanged ?
                $this->calculatingShippingFee($address) :
                $deliveryCost;
            
            Log::debug('Compare delivery cost', [
                'The same address: ' => $finalDeliveryCost === $deliveryCost
            ]);

            $order = Order::create([
                'user_id' => $userId,
                'voucher_id' => $voucherId,
                'total_price' => $totalPrice,
                'provisional_price' => $provisionalPrice,
                'deliver_cost' => $finalDeliveryCost,
                'payment_method' => $paymentMethod,
                'addtion_note' => $additionalNote
            ]);
            
            Log::debug('Order created', [
                'order_id' => $order->getAttribute('order_id'),
                'final_delivery_cost' => $finalDeliveryCost
            ]);

            return $order;
        } catch (Exception $e) {
            Log::error('Order creation failed', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
                'address' => $address
            ]);
            return response()->json([
                'success'=> false,
                'message'=> $e->getMessage()
            ]);
        }
	}

    public function showSuccessPage($orderId){
        $order = Order::findOrFail($orderId);
        return view('orders.success', [
            'order' => $order
        ]);
    }
}