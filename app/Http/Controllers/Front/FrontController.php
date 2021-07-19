<?php

namespace App\Http\Controllers\Front;

use App\Tax;
use App\Deal;
use App\Page;
use App\Role;
use App\User;
use App\Leave;
use App\Media;
use App\OfficeLeave;
use App\Company;
use App\Coupon;
use App\ItemTax;
use App\Booking;
use App\Package;
use App\Category;
use App\Currency;
use App\FrontFaq;
use App\Language;
use App\Location;
use App\Spotlight;
use Carbon\Carbon;
use App\VendorPage;
use App\BookingItem;
use App\BookingTime;
use App\Helper\Reply;
use App\GlobalSetting;
use App\BusinessService;
use App\UniversalSearch;
use App\EmployeeSchedule;
use App\Facades\Razorpay;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use App\Notifications\NewUser;
use App\Notifications\ContactUs;
use App\Notifications\NewBooking;
use App\PaymentGatewayCredentials;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use App\Notifications\CompanyWelcome;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Session;
use App\Http\Requests\StoreFrontBooking;
use App\Notifications\BookingConfirmation;
use App\Http\Requests\Front\ContactRequest;
use App\Http\Requests\Front\CartPageRequest;
use Illuminate\Support\Facades\Notification;
use App\Http\Controllers\FrontBaseController;
use App\Http\Requests\Company\RegisterCompany;
use App\Http\Requests\ApplyCoupon\ApplyRequest;
use App\Notifications\SuperadminNotificationAboutNewAddedCompany;

class FrontController extends FrontBaseController
{
    public function __construct()
    {
        parent::__construct();

    }

    public function index()
    {
        $couponData = json_decode(request()->cookie('couponData'), true);
        if ($couponData) {
            setcookie("couponData", "", time() - 3600);
        }

        if (request()->ajax())
        {
            /* LOCATION */
            $location_id = request()->location_id;

            /* CATRGORIES */
            $categories = Category::active()->withoutGlobalScope('company')
            ->activeCompanyService()
            ->with(['services' => function ($query)  use($location_id) {
                $query->active()->withoutGlobalScope('company')->where('location_id', $location_id);
            }])
            ->withCount(['services' => function ($query) use($location_id) {
                $query->withoutGlobalScope('company')->where('location_id', $location_id);
            }]);

            $total_categories_count = $categories->count();
            $categories = $categories->take(8)->get();


            /* DEALS */
            $deals = Deal::withoutGlobalScope('company')
            ->active()
            ->activeCompany()
            ->with(['location', 'services', 'company'=> function($q) { $q->withoutGlobalScope('company'); } ])
            ->where('start_date_time', '<=', Carbon::now()->setTimezone($this->settings->timezone))
            ->where('end_date_time', '>=', Carbon::now()->setTimezone($this->settings->timezone))
            ->where('location_id', $location_id);

            $total_deals_count = $deals->count();
            $deals = $deals->take(10)->get();

            $spotlight = Spotlight::with(['deal', 'company'=> function($q) { $q->withoutGlobalScope('company'); } ])
            ->activeCompany()
            ->whereHas('deal', function($q) use($location_id){
                $q->whereHas('location', function ($q) use($location_id) {
                    $q->where('location_id', $location_id);
                });
            })
            ->orderBy('sequence', 'asc')->get();

            return Reply::dataOnly(['categories' => $categories, 'total_categories_count' => $total_categories_count, 'deals' => $deals, 'total_deals_count' => $total_deals_count, 'spotlight' => $spotlight]);
        }

        /* COUPON */
        $coupons = Coupon::active();

        $this->coupons = $coupons->take(12)->get();

        $this->sliderContents = Media::all();

        return view('front.index', $this->data);
    }

    public function addOrUpdateProduct(Request $request)
    {

        $newProduct = [
            "type" => $request->type,
            "unique_id" => $request->unique_id,
            "companyId" => $request->companyId,
            "price" => $request->price,
            "name" => $request->name,
            "id" => $request->id,
        ];


        $products = [];
        $quantity = $request->quantity ?? 1;

        if($request->type == 'deal'){

            $deals = Deal::withoutGlobalScope('company')->where('id', $request->id)
            ->with([
                'dealTaxes' => function($q) { $q->withoutGlobalScope('company'); }
            ])->first();

            $tax = [];
            if ($deals->dealTaxes) {
                foreach ($deals->dealTaxes as $key => $deal) {
                    $taxDetail = Tax::select('id', 'name', 'percent')->active()->where('id', $deal->tax_id)->first();
                    $tax[] = $taxDetail;
                }
            }

            $newProduct = Arr::add($newProduct, 'tax', json_encode($tax));
            $newProduct = Arr::add($newProduct, 'max_order', $request->max_order);
        }

        if ($request->type == 'service')
        {
            $services = BusinessService::withoutGlobalScope('company')->where('id', $request->id)
            ->with([
                'taxServices' => function($q) { $q->withoutGlobalScope('company'); }
            ])->first();

            $tax = [];
            if ($services->taxServices) {
                foreach ($services->taxServices as $key => $service) {
                    $taxDetail = Tax::select('id', 'name', 'percent')->active()->where('id', $service->tax_id)->first();
                    $tax[] = $taxDetail;
                }
            }
            $newProduct = Arr::add($newProduct, 'tax', json_encode($tax));
        }

        if (!$request->hasCookie('products'))
        {
            $newProduct = Arr::add($newProduct, 'quantity', $quantity);
            $newProduct = Arr::add($newProduct, 'quantity', $quantity);
            $products = Arr::add($products, $request->unique_id, $newProduct);

            return response([
                'status' => 'success',
                'message' => __('messages.front.success.productAddedToCart'),
                'productsCount' => sizeof($products)
            ])->cookie('products', json_encode($products));
        }

        $products = json_decode($request->cookie('products'), true);

        /* if type is deal and max_order_per_customer is exceeded then block increasing quantity */
        if($request->type == 'deal' && array_key_exists($request->unique_id, $products) && $this->checkDealQuantity($request->id) !== 0 && $this->checkDealQuantity($request->id) <= $products[$request->unique_id]['quantity']) {
            return Reply::error(__('app.maxDealMessage', ['quantity' => $this->checkDealQuantity($request->id)]));
        }

        /* Checking if item belongs to some other company */
        $companyIds = [];
        $types = [];
        foreach ($products as $key => $product)
        {
            $companyIds[] = $product['companyId'];
            $types[] = $product['type'];
        }

        /* check if incoming service belong to same company as cart has */
        if (!in_array($request->companyId, $companyIds))
        {
            return response(['result' => 'fail', 'message' => __('messages.front.errors.differentItemFound')])->cookie('products', json_encode($products));
        }

        /* Checking if item has different type then cart item */
        if (!in_array($request->type, $types))
        {
            return response(['result' => 'fail', 'message' => __('messages.front.errors.addOneItemAtATime')])->cookie('products', json_encode($products));
        }

        if (!array_key_exists($request->unique_id, $products))
        {
            $newProduct = Arr::add($newProduct, 'quantity', $quantity);
            $newProduct = Arr::add($newProduct, 'tax', json_encode($tax));
            $products = Arr::add($products, $request->unique_id, $newProduct);

            return response([
                'status' => 'success',
                'message' => __('messages.front.success.productAddedToCart'),
                'productsCount' => sizeof($products)
            ])->cookie('products', json_encode($products));
        }
        else
        {
            if ($request->quantity) {
                $products[$request->unique_id]['quantity'] = $request->quantity;
            } else {
                $products[$request->unique_id]['quantity'] += 1;
            }
        }

        return response([
            'status' => 'success',
            'message' => __('messages.front.success.cartUpdated'),
            'productsCount' => sizeof($products)
        ])->cookie('products', json_encode($products));
    }

    public function bookingPage(Request $request)
    {
        $bookingDetails = [];

        if ($request->hasCookie('bookingDetails')) {
            $bookingDetails = json_decode($request->cookie('bookingDetails'), true);
        }

        if ($request->ajax()) {
            return Reply::dataOnly(['status' => 'success', 'productsCount' => $this->productsCount]);
        }

        $locale = App::getLocale();

        return view('front.booking_page', compact('bookingDetails', 'locale'));
    }

    public function addBookingDetails(CartPageRequest $request)
    {
        $expireTime = Carbon::parse($request->bookingDate . ' ' . $request->bookingTime, $this->settings->timezone);
        $cookieTime = Carbon::now()->setTimezone($this->settings->timezone)->diffInMinutes($expireTime);

        $emp_name = '';
        if (!empty($request->selected_user)) {
            $emp_name = User::find($request->selected_user)->name;
        }

        return response(Reply::dataOnly(['status' => 'success']))->cookie('bookingDetails', json_encode(['bookingDate' => $request->bookingDate, 'bookingTime' => $request->bookingTime, 'selected_user' => $request->selected_user, 'emp_name' => $emp_name]), $cookieTime);
    }

    public function cartPage(Request $request)
    {
        $products       = json_decode($request->cookie('products'), true);
        $bookingDetails = json_decode($request->cookie('bookingDetails'), true);
        $couponData     = json_decode($request->cookie('couponData'), true);
        $taxes          = Tax::active()->get();
        $commission     = PaymentGatewayCredentials::first();
        $type = '';
        if(!is_null(json_decode($request->cookie('products'), true)))
        {
            $product = (array) json_decode(request()->cookie('products', true));
            $keys = array_keys($product);
            $type = $product[$keys[0]]->type == 'deal' ? 'deal' : 'booking';
        }

        return view('front.cart', compact('commission', 'products', 'taxes', 'bookingDetails', 'couponData', 'type'));
    }

    public function deleteProduct(Request $request, $id)
    {
        $products = json_decode($request->cookie('products'), true);

        if ($id != 'all') {
            Arr::forget($products, $id);
        } else {

            $productsCount = is_null($products) ? 0 : sizeof($products);

            return response(Reply::successWithData(__('messages.front.success.cartCleared'), ['action' => 'redirect', 'url' => route('front.cartPage'), 'productsCount' => $productsCount]))
                ->withCookie(Cookie::forget('bookingDetails'))
                ->withCookie(Cookie::forget('products'))
                ->withCookie(Cookie::forget('couponData'));
        }

        if (sizeof($products) > 0) {
            setcookie("products", "", time() - 3600);
            return response(Reply::successWithData(__('messages.front.success.productDeleted'), ['productsCount' => sizeof($products), 'products' => $products]))->cookie('products', json_encode($products));
        }

        return response(Reply::successWithData(__('messages.front.success.cartCleared'), ['action' => 'redirect', 'url' => route('front.cartPage'), 'productsCount' => sizeof($products)]))->withCookie(Cookie::forget('bookingDetails'))->withCookie(Cookie::forget('products'))->withCookie(Cookie::forget('couponData'));
    }

    public function updateCart(Request $request)
    {
        $product = $request->products;

        if($request->type == 'deal' && $request->currentValue > $request->max_order)
        {
            $product[$request->unique_id]['quantity'] = $request->max_order;

            return response(Reply::error(__('app.maxDealMessage', ['quantity' => $request->max_order])));
        }
        return response(Reply::success(__('messages.front.success.cartUpdated')))->cookie('products', json_encode($product));
    }

    public function checkoutPage()
    {
        $products = (array) json_decode(request()->cookie('products', true));
        $keys = array_keys($products);

        $request_type = $products[$keys[0]]->type == 'deal' ? 'deal' : 'booking';

        $emp_name = '';
        if (!empty(json_decode(request()->cookie('bookingDetails'))->selected_user)) {
            $emp_name = User::find(json_decode(request()->cookie('bookingDetails'))->selected_user)->name;
        }

        $bookingDetails = request()->hasCookie('bookingDetails') ? json_decode(request()->cookie('bookingDetails'), true) : [];
        $couponData     = request()->hasCookie('couponData') ? json_decode(request()->cookie('couponData'), true) : [];

        $Amt = 0;
        $tax = 0;
        $totalAmt = 0;
        $taxAmount = 0;
        if ($request_type !== 'deal') {

            foreach ($products as $key => $service) {
                $taxes = ItemTax::with('tax')->where('service_id', $service->id)->get();
                $tax = 0;
                foreach ($taxes as $key => $value) {
                    $tax += $value->tax->percent;
                }
                $Amt = $service->price * $service->quantity;
                $taxAmount += ($Amt*$tax)/100;
                $totalAmt += $service->price * $service->quantity;
            }

        }else {

            foreach ($products as $key => $deal) {
                $taxes = ItemTax::with('tax')->where('deal_id', $deal->id)->get();
                $tax = 0;


                foreach ($taxes as $key => $value) {
                    $tax += $value->tax->percent;
                }
                $Amt = $deal->price * $deal->quantity;
                $taxAmount += ($Amt*$tax)/100;
                $totalAmt += $deal->price * $deal->quantity;
            }
        }

        if ($tax) {
           // $totalAmt = $taxAmount + $totalAmt;
           $totalAmt = $totalAmt;
        }

        if ($couponData) {
            $totalAmt -= $couponData['applyAmount'];
        }

        if ($tax) {
            $totalAmount = round($totalAmt, 2);
        }
        return view('front.checkout_page', compact('totalAmount', 'bookingDetails', 'request_type', 'emp_name'));
    }

    public function paymentFail(Request $request, $bookingId = null)
    {
        $credentials = PaymentGatewayCredentials::withoutGlobalScope('company')->first();
        if ($bookingId == null) {
            $booking = Booking::where([
                'user_id' => $this->user->id
            ])
                ->latest()
                ->first();
        } else {
            $booking = Booking::where(['id' => $bookingId, 'user_id' => $this->user->id])->first();
        }

        $setting = Company::with('currency')->first();
        $user = $this->user;

        return view('front.payment', compact('credentials', 'booking', 'user', 'setting'));
    }

    public function paymentSuccess(Request $request, $bookingId = null)
    {
        $credentials = PaymentGatewayCredentials::withoutGlobalScope('company')->first();
        if ($bookingId == null) {
            $booking = Booking::where([
                'user_id' => $this->user->id
            ])
                ->latest()
                ->first();
        } else {
            $booking = Booking::where(['id' => $bookingId, 'user_id' => $this->user->id])->first();
        }

        $setting = Company::with('currency')->first();
        $user = $this->user;

        if ($booking->payment_status !== 'completed') {
            $booking->payment_status = 'completed';
            $booking->save();
        }

        return view('front.payment', compact('credentials', 'booking', 'user', 'setting'));
    }

    public function paymentGateway(Request $request)
    {
        if(!Auth::user()){
            return $this->logout();
        }
        $credentials = PaymentGatewayCredentials::withoutGlobalScope('company')->first();

        $booking = Booking::with('deal', 'users')->where([
            'user_id' => $this->user->id
        ])
            ->latest()
            ->first();

        $emp_name = '';

        if (array_key_exists(0, $booking->users->toArray())) {
            $emp_name = $booking->users->toArray()[0]['name'];
        }

        $setting = Company::with('currency')->first();
        $globalSetting = GlobalSetting::with('currency')->first();
        $frontThemeSetting = $this->frontThemeSettings;
        $user = $this->user;

        if ($booking->payment_status == 'completed') {
            return redirect(route('front.index'));
        }

        return view('front.payment-gateway', compact('credentials', 'booking', 'user', 'setting', 'globalSetting', 'frontThemeSetting', 'emp_name'));
    }

    public function offlinePayment($bookingId = null, $return_url = null)
    {
        if ($bookingId == null) {
            $booking = Booking::where([ 'user_id' => $this->user->id ])->latest()->first();
        } else {
            $booking = Booking::where(['id' => $bookingId, 'user_id' => $this->user->id])->first();
        }

        if (!$booking || $booking->payment_status == 'completed') {

            return redirect()->route('front.index');
        }

        $booking->payment_status = 'pending';
        $booking->save();

        $admins = User::allAdministrators()->where('company_id', $booking->company_id)->first();
        Notification::send($admins, new NewBooking($booking));
        $booking->user->notify(new BookingConfirmation($booking));

        if ($return_url != null && $return_url = 'calendarPage') {

            Session::put('success', __('messages.updatedSuccessfully'));
            return redirect()->route('admin.bookings.index');
        }
        return view('front.booking_success');
    }

    public function bookingSlots(Request $request)
    {
        $booking_per_day =  $this->getCartCompanyDetail()->booking_per_day;

        $companyId = $this->getCartCompanyDetail()->id;

        if (!is_null($this->user) && $booking_per_day !=(0||'') && $booking_per_day <= $this->user->userBookingCount(Carbon::createFromFormat('Y-m-d', $request->bookingDate)))
        {
            $msg = __('messages.reachMaxBooking') . Carbon::createFromFormat('Y-m-d', $request->bookingDate)->format('Y-m-d');
            return Reply::dataOnly(['status' => 'fail', 'msg' => $msg]);
        }

        $bookingDate = Carbon::createFromFormat('Y-m-d', $request->bookingDate);
        $day = $bookingDate->format('l');
        $bookingTime = BookingTime::withoutGlobalScope('company')->where('company_id', $companyId)->where('day', strtolower($day))->first();
        //check if multiple booking allowed
        $bookings = Booking::withoutGlobalScope('company')->where('company_id', $companyId)->select('id', 'date_time')->where(DB::raw('DATE(date_time)'), $bookingDate->format('Y-m-d'));
        $officeLeaves = OfficeLeave::where('start_date', '<=', $bookingDate )
         ->where('end_date', '>=', $bookingDate)
          ->get();

        if($officeLeaves->count()>0){
            $msg =__('messages.ShopClosed');
            return Reply::dataOnly(['status' => 'shopclosed', 'msg' => $msg]);

        }
        if ($bookingTime->per_day_max_booking !=(0||'') && $bookingTime->per_day_max_booking <= $bookings->count())
        {
            $msg = __('messages.reachMaxBookingPerDay') . Carbon::createFromFormat('Y-m-d', $request->bookingDate)->format('Y-m-d');
            return Reply::dataOnly(['status' => 'fail', 'msg' => $msg]);
        }

        if ($bookingTime->multiple_booking == 'no') {
            $bookings = $bookings->get();
        } else {
            $bookings = $bookings->whereRaw('DAYOFWEEK(date_time) = ' . ($bookingDate->dayOfWeek + 1))->get();
        }

        $variables = compact('bookingTime', 'bookings');

        if ($bookingTime->status == 'enabled') {
            if ($bookingDate->day == Carbon::today()->day) {
                $startTime = Carbon::createFromFormat($this->settings->time_format, $bookingTime->utc_start_time);
                while ($startTime->lessThan(Carbon::now())) {
                    $startTime = $startTime->addMinutes($bookingTime->slot_duration);
                }
            } else {
                $startTime = Carbon::createFromFormat($this->settings->time_format, $bookingTime->utc_start_time);
            }
            $endTime = Carbon::createFromFormat($this->settings->time_format, $bookingTime->utc_end_time);

            $startTime->setTimezone($this->settings->timezone);
            $endTime->setTimezone($this->settings->timezone);

            $startTime->setDate($bookingDate->year, $bookingDate->month, $bookingDate->day);
            $endTime->setDate($bookingDate->year, $bookingDate->month, $bookingDate->day);

            $variables = compact('startTime', 'endTime', 'bookingTime', 'bookings');
        }
        $view = view('front.booking_slots', $variables)->render();
        return Reply::dataOnly(['status' => 'success', 'view' => $view]);
    }

    public function saveBooking(StoreFrontBooking $request)
    {

        /* if user is registered then login else do register */
        if ($this->user) {
            $user = $this->user;
        } else
        {
            $user = User::firstOrNew(['email' => $request->email]);
            $user->name = $request->first_name . ' ' . $request->last_name;
            $user->email = $request->email;
            $user->mobile = $request->phone;
            $user->calling_code = $request->calling_code;
            $user->password = '123456';
            $user->save();

            $user->attachRole(Role::where('name', 'customer')->first()->id);

            Auth::loginUsingId($user->id);
            $this->user = $user;

            if ($this->smsSettings->nexmo_status == 'active' && !$user->mobile_verified) {
                // verify user mobile number
                return response(Reply::redirect(route('front.checkoutPage'), __('messages.front.success.userCreated')));
            }

            $user->notify(new NewUser('123456'));
        }

        $products = (array) json_decode(request()->cookie('products', true));
        $keys = array_keys($products);
        $type = $products[$keys[0]]->type == 'deal' ? 'deal' : 'booking';

        // get products and bookingDetails
        $products       = json_decode($request->cookie('products'), true);

        // Get Applied Coupon Details
        $couponData     = request()->hasCookie('couponData') ? json_decode(request()->cookie('couponData'), true) : [];

        /* booking details having bookingDate, bookingTime, selected_user, emp_name */
        $bookingDetails = json_decode($request->cookie('bookingDetails'), true);

        if (is_null($products) && ($type !='deal' || is_null($bookingDetails))) {
            return response(Reply::redirect(route('front.index')));
        }

        if($type == 'booking')
        {
            // get bookings and bookingTime as per bookingDetails date
            $bookingDate = Carbon::createFromFormat('Y-m-d', $bookingDetails['bookingDate']);
            $day = $bookingDate->format('l');
            $bookingTime = BookingTime::where('day', strtolower($day))->first();

            $bookings = Booking::select('id', 'date_time')->where(DB::raw('DATE(date_time)'), $bookingDate->format('Y-m-d'))->whereRaw('DAYOFWEEK(date_time) = ' . ($bookingDate->dayOfWeek + 1))->get();

            if ($bookingTime->max_booking != 0 && $bookings->count() > $bookingTime->max_booking) {
                return response(Reply::redirect(route('front.bookingPage')))->withCookie(Cookie::forget('bookingDetails'));
            }
        }

        $originalAmount = $taxAmount = $amountToPay = $discountAmount = $couponDiscountAmount = 0;

        $bookingItems = array();

        $companyId = 0;

        $tax = 0;
        $taxAmount = 0;
        $taxName = [];
        $taxPercent = 0;
        $Amt = 0;

        foreach ($products as $key => $product) {
            $companyId = $product['companyId'];

            $amount = converted_original_price($companyId,($product['quantity'] * $product['price']));

            $deal_id = ($product['type'] == 'deal') ? $product['id'] : null;

            $business_service_id = ($product['type'] == 'service') ? $product['id'] : null;

            $bookingItems[] = [
                "business_service_id" => $business_service_id,
                "quantity" => $product['quantity'],
                "unit_price" => converted_original_price($companyId,$product['price']),
                "amount" => $amount,
                "deal_id" => $deal_id,
            ];

            $originalAmount = ($originalAmount + $amount);

            if ($type!=='deal'){
                $taxes = ItemTax::with('tax')->where('service_id', $product['id'])->get();
            }else {
                $taxes = ItemTax::with('tax')->where('deal_id', $product['id'])->get();
            }
            $tax = 0;
            foreach ($taxes as $key => $value) {
                $tax += $value->tax->percent;
                $taxName[] = $value->tax->name;
                $taxPercent += $value->tax->percent;
            }
            $Amt = $product['price'] * $product['quantity'];
            $taxAmount += ($Amt*$tax)/100;
        }

        //$amountToPay = ($originalAmount + $taxAmount);
        $amountToPay = ($originalAmount);

        if ($couponData) {
            $amountToPay -= $couponData['applyAmount'];
            $couponDiscountAmount = $couponData['applyAmount'];
        }

        $amountToPay = round($amountToPay, 2);

        $dateTime = $type !== 'deal' ? Carbon::createFromFormat('Y-m-d', $bookingDetails['bookingDate'])->format('Y-m-d') . ' ' . Carbon::createFromFormat('H:i:s', $bookingDetails['bookingTime'])->format('H:i:s') : '';


        $booking = new Booking();
        $booking->company_id = $companyId;
        $booking->user_id = $user->id;
        $booking->currency_id = Company::withoutGlobalScope('company')->find($companyId)->currency_id;
        $booking->date_time = $dateTime;
        $booking->status = 'pending';
        $booking->payment_gateway = 'cash';
        $booking->original_amount = $originalAmount;
        $booking->discount = $discountAmount;
        $booking->discount_percent = '0';
        $booking->payment_status = 'pending';
        $booking->additional_notes = $request->additional_notes;
        $booking->location_id = $request->location;
        $booking->source = 'online';
        if (!is_null($tax)) {
            $booking->tax_name = json_encode($taxName);
            $booking->tax_percent = $taxPercent;
            $booking->tax_amount = $taxAmount;
        }
        if (sizeof($couponData) > 0 && !is_null($couponData)) {
            $booking->coupon_id = $couponData[0]['id'];
            $booking->coupon_discount = $couponDiscountAmount;
            $coupon = Coupon::findOrFail($couponData[0]['id']);
            $coupon->used_time = ($coupon->used_time + 1);
            $coupon->save();
        }
        $booking->amount_to_pay = $amountToPay;
        $booking->save();

        // create and save order for razorpay
        $data = [
            'amount' => $booking->converted_amount_to_pay * 100,
            'currency' => $this->settings->currency->currency_code,
        ];

        $credentials = PaymentGatewayCredentials::withoutGlobalScope('company')->first();
        if ($credentials->razorpay_status === 'active') {
            $booking->order_id = Razorpay::createOrder($data)->id;
        }

        $booking->save();

        if($type !== 'deal')
        {
            // /* Assign Suggested User To Booking */
            if (!empty(json_decode($request->cookie('bookingDetails'))->selected_user)) {
                $booking->users()->attach(json_decode($request->cookie('bookingDetails'))->selected_user);
                setcookie("selected_user", "", time() - 3600);
            } else {
                if ($this->suggestEmployee($booking->date_time)) {
                    $booking->users()->attach($this->suggestEmployee($booking->date_time));
                    setcookie("user_id", "", time() - 3600);
                }
            }
        }

        foreach ($bookingItems as $key => $bookingItem) {
            $bookingItems[$key]['booking_id'] = $booking->id;
            $bookingItems[$key]['company_id'] = $companyId;
        }

        DB::table('booking_items')->insert($bookingItems);

        return response(Reply::redirect(route('front.payment-gateway'), __('messages.front.success.bookingCreated')))->withCookie(Cookie::forget('bookingDetails'))->withCookie(Cookie::forget('couponData'))->withCookie(Cookie::forget('products'));

    }

    public function searchServices(Request $request)
    {

        $search = strtolower($request->q);
        $route = Route::currentRouteName();

        if ($search != '')
        {
            $universalSearches = UniversalSearch::withoutGlobalScope('company')->where('title', $search)->first();
            if($universalSearches != null) {
                $universalSearch = UniversalSearch::withoutGlobalScope('company')->findOrFail($universalSearches->id);
                $universalSearch->count += 1;
                $universalSearch->save();
            }
            elseif($universalSearches == null) {
                $universalSearch = new UniversalSearch();
                $universalSearch->location_id = $request->l;
                $universalSearch->searchable_id = 'keywords';
                $universalSearch->searchable_type = 'service';
                $universalSearch->title = $search;
                $universalSearch->route_name = $route;
                $universalSearch->count = 1;
                $universalSearch->type = 'frontend';
                $universalSearch->save();
            }
        }

        $categories = Category::get();
        $company_id = $category_id = '';

        return view('front.all_services', compact('categories', 'category_id', 'company_id'));
    }

    public function contact(ContactRequest $request)
    {
        $globalSetting = GlobalSetting::select('id', 'contact_email', 'company_name')->first();

        Notification::route('mail', $globalSetting->contact_email)
        ->notify(new ContactUs($globalSetting));

        return Reply::success(__('messages.front.success.emailSent'));
    }

    public function serviceDetail(Request $request, $categorySlug, $serviceSlug)
    {
        $service = BusinessService::where('slug', $serviceSlug)
        ->activeCompany()
        ->withoutGlobalScope('company')
        ->with([
                'company' => function($q){
                    $q->withoutGlobalScope('company');
                },
                'location' => function($q){
                    $q->withoutGlobalScope('company');
                },
            ])
        ->whereHas('category', function ($q) use($categorySlug) {
            $q->whereSlug($categorySlug);
        })
        ->first();

        $products = json_decode($request->cookie('products'), true) ?: [];
        $reqProduct = array_filter($products, function ($product) use ($service) {
            return $product['unique_id'] == 'service'.$service->id;
        });

        if($service){
            return view('front.service_detail', compact('service', 'reqProduct'));
        }
        abort(404);
    }

    public function dealDetail(Request $request, $dealSlug)
    {
        $deal = Deal::withoutGlobalScope('company')
        ->activeCompany()
        ->with([
            'company' => function($q){
                $q->withoutGlobalScope('company');
            },
            'location' => function($q){
                $q->withoutGlobalScope('company');
            },
        ])
        ->where('slug', $dealSlug)->first();

        /* to show update cart and delete item */

        $products = json_decode($request->cookie('products'), true) ?: [];
        $reqProduct = array_filter($products, function ($product) use ($deal) {
            return $product['unique_id'] == 'deal'.$deal->id;
        });

        if($deal){
            return view('front.deal_detail', compact('deal', 'reqProduct'));
        }
        abort(404);
    }

    public function allLocations()
    {
        $locations = Location::active()->get();
        return Reply::dataOnly(['locations' => $locations]);
    }

    public function page($slug)
    {
        $page = Page::where('slug', $slug)->firstorFail();
        return view('front.page', compact('page'));
    }

    public function changeLanguage($code)
    {
        $language = Language::where('language_code', $code)->first();

        if (!$language) {
            return Reply::error(__('messages.coupon.invalidCode'));
        }

        return response(Reply::dataOnly(['message' => __('messages.languageChangedSuccessfully')]))->withCookie(cookie('appointo_multi_vendor_language_code', $code));
    }

    public function applyCoupon(ApplyRequest $request)
    {
        $couponCode         = strtolower($request->coupon);
        $products           = json_decode($request->cookie('products'), true);
        $tax                = Tax::active()->first();
        $couponCompanyIds   = [];
        $productAmount      = 0;

        if (!$products) {
            return Reply::error(__('messages.coupon.addProduct'));
        }

        foreach ($products as $product) {
            $productAmount += $product['price'] * $product['quantity'];
            $couponCompanyIds[] = $product['companyId'];
        }

        /* check if coupon code exist. */
        if(is_null($couponCompanyIds) && $couponCompanyIds == null) {
            return Reply::error(__('messages.coupon.invalidCode'));
        }

        if ($tax == null) {
            $percentAmount = 0;
        } else {
            $percentAmount = ($tax->percent / 100) * $productAmount;
        }

        $totalAmount   = ($productAmount + $percentAmount);

        $currentDate = Carbon::now()->format('Y-m-d H:i:s');

        $couponData = Coupon::where('coupons.start_date_time', '<=', $currentDate)
            ->where(function ($query) use ($currentDate) {
                $query->whereNull('coupons.end_date_time')
                    ->orWhere('coupons.end_date_time', '>=', $currentDate);
            })
            ->where('coupons.status', 'active')
            ->where('coupons.code', $couponCode)
            ->first();

        if (!is_null($couponData)  && $couponData->minimum_purchase_amount != 0 && $couponData->minimum_purchase_amount != null && $productAmount < $couponData->minimum_purchase_amount) {
            return Reply::error(__('messages.coupon.minimumAmount') . ' ' . currency_formatter($couponData->minimum_purchase_amount));
        }

        if (!is_null($couponData) && $couponData->used_time >= $couponData->uses_limit && $couponData->uses_limit != null && $couponData->uses_limit != 0) {
            return Reply::error(__('messages.coupon.usedMaximun'));
        }

        if (!is_null($couponData)) {
            $days = json_decode($couponData->days);
            $currentDay = Carbon::now()->format('l');
            if (in_array($currentDay, $days)) {
                if (!is_null($couponData->amount) && $couponData->amount !== 0 && $couponData->discount_type === 'percentage') {
                    $percentAmnt = round(($couponData->amount / 100) * $totalAmount, 2);
                    if (!is_null($couponData->amount) && $percentAmnt >= $couponData->amount) {
                        $percentAmnt = $couponData->amount;
                    }
                    return response(Reply::dataOnly(['amount' => $percentAmnt, 'couponData' => $couponData]))->cookie('couponData', json_encode([$couponData, 'applyAmount' => $percentAmnt]));
                } elseif (!is_null($couponData->amount) && $couponData->amount !== 0 && $couponData->discount_type === 'amount') {
                    return response(Reply::dataOnly(['amount' => $couponData->amount, 'couponData' => $couponData]))->cookie('couponData', json_encode([$couponData, 'applyAmount' => $couponData->amount]));
                }
            } else {
                return response(
                    Reply::error(__(
                        'messages.coupon.notValidToday',
                        ['day' => __('app.' . strtolower($currentDay))]
                    ))
                );
            }
        }
        return Reply::error(__('messages.coupon.notMatched'));
    }

    public function updateCoupon(Request $request)
    {
        $couponTitle = strtolower($request->coupon);
        $products    = json_decode($request->cookie('products'), true);
        $tax         = Tax::active()->first();

        $productAmount = 0;

        foreach ($products as $product) {
            $productAmount += $product['price'] * $product['quantity'];
        }

        $percentAmount = ($tax->percent / 100) * $productAmount;
        $totalAmount   = ($productAmount + $percentAmount);

        $currentDate = Carbon::now()->format('Y-m-d H:i:s');

        $couponData = Coupon::where('coupons.start_date_time', '<=', $currentDate)
            ->where(function ($query) use ($currentDate) {
                $query->whereNull('coupons.end_date_time')
                    ->orWhere('coupons.end_date_time', '>=', $currentDate);
            })
            ->where('coupons.status', 'active')
            ->where('coupons.title', $couponTitle)
            ->first();

        if (!is_null($couponData)  && $couponData->minimum_purchase_amount != 0 && $couponData->minimum_purchase_amount != null && $productAmount < $couponData->minimum_purchase_amount) {
            return Reply::errorWithoutMessage();
        }

        if (!is_null($couponData) && $couponData->used_time >= $couponData->uses_limit && $couponData->uses_limit != null && $couponData->uses_limit != 0) {
            return Reply::errorWithoutMessage();
        }

        if (!is_null($couponData) && $productAmount > 0) {
            $days = json_decode($couponData->days);
            $currentDay = Carbon::now()->format('l');
            if (in_array($currentDay, $days)) {
                if (!is_null($couponData->percent) && $couponData->percent != 0) {
                    $percentAmnt = round(($couponData->percent / 100) * $totalAmount, 2);
                    if (!is_null($couponData->amount) && $percentAmnt >= $couponData->amount) {
                        $percentAmnt = $couponData->amount;
                    }
                    return response(Reply::dataOnly(['amount' => $percentAmnt, 'couponData' => $couponData]))->cookie('couponData', json_encode([$couponData, 'applyAmount' => $percentAmnt]));
                } elseif (!is_null($couponData->amount) && (is_null($couponData->percent) || $couponData->percent == 0)) {
                    return response(Reply::dataOnly(['amount' => $couponData->amount, 'couponData' => $couponData]))->cookie('couponData', json_encode([$couponData, 'applyAmount' => $couponData->amount]));
                }
            } else {
                return Reply::errorWithoutMessage();
            }
        }
        return Reply::errorWithoutMessage();
    }

    public function removeCoupon(Request $request)
    {
        return response(Reply::dataOnly([]))->withCookie(Cookie::forget('couponData'));
    }

    public function checkUserAvailability(Request $request)
    {

        $companyId = $this->getCartCompanyDetail()->id;

        /* check for all employee of that service, of that particular location  */
        $dateTime = Carbon::createFromFormat('Y-m-d H:i:s', $request->date, $this->settings->timezone)->setTimezone('UTC');

        [$service_ids, $service_names] = Arr::divide(json_decode($request->cookie('products'), true));

        $user_lists = BusinessService::with('users')->where('company_id', $companyId)->whereIn('id', $service_ids)->get();

        $all_users_of_particular_services = array();
        foreach($user_lists as $user_list) {
            foreach($user_list->users as $user) {
                $all_users_of_particular_services[] = $user->id;
            }
        }


        /* Employee schedule: */
        $day = $dateTime->format('l');
        $time = $dateTime->format('H:i:s');
        $date = $dateTime->format('Y-m-d');
        $bookingTime = BookingTime::where('day', strtolower($day))->first();
        $slot_select=$date.' '.$time;


        $booking_slot = DB::table('bookings')->whereBetween('date_time', [$slot_select,$dateTime->addMinute($bookingTime->slot_duration)])
        ->get();



        /* Maximum Number of Booking Allowed Per Slot check */
        if ($bookingTime->per_slot_max_booking !=(0||'')  && $bookingTime->per_slot_max_booking<=$booking_slot->count() )
        {

            return response(Reply::dataOnly(['status' => 'fail']));
        }

        /* if no employee for that particular service is found then allow booking with null employee assignment  */
        if(empty($all_users_of_particular_services)) {
            return response(Reply::dataOnly(['continue_booking'=>'yes']));
        }

        /* Check for employees working on that day: */
        $employeeWorking = EmployeeSchedule::with('employee')->where('company_id', $companyId)->where('days', $day)
        ->whereTime('start_time', '<=', $time)->whereTime('end_time', '>=', $time)
        ->where('is_working', 'yes')->whereIn('employee_id', $all_users_of_particular_services)->get();


        $working_employee = array();
        foreach($employeeWorking as $employeeWorkings) {
                $working_employee[] = $employeeWorkings->employee->id;
        }


        $assigned_user_list_array = array();
        $assigned_users_list =  Booking::with('users')->where('company_id', $companyId)
        ->where('date_time' , $dateTime)
        ->get();

        foreach ($assigned_users_list as $key => $value) {
            foreach ($value->users as $key1 => $value1) {
                $assigned_user_list_array[] = $value1->id;
            }
        }

        $free_employee_list = array_diff($working_employee, array_intersect($working_employee, $assigned_user_list_array));

        $select_user = '<select name="" id="selected_user" name="selected_user" class="form-control mt-3"><option value="">--Select Employee--</option>';

        /* Leave: */

        /* check for half day */
        $halfday_leave = Leave::with('employee')->where('company_id', $companyId)->whereDate('start_date', '<=', $date)
        ->whereDate('end_date', '>=', $date)->whereTime('start_time', '<=', $time)
        ->whereTime('end_time', '>=', $time)->where('leave_type', 'Half day')->where('status', 'approved')->get();

        $users_on_halfday_leave = array();
        foreach($halfday_leave as $halfday_leaves) {
                $users_on_halfday_leave[] = $halfday_leaves->employee->id;
        }

        /* check for full day */
        $fullday_leave = Leave::with('employee')->where('company_id', $companyId)->whereDate('start_date', '<=', $date)
        ->whereDate('end_date', '>=', $date)->where('leave_type', 'Full day')->where('status', 'approved')->get();

        $users_on_fullday_leave = array();
        foreach($fullday_leave as $fullday_leaves) {
                $users_on_fullday_leave[] = $fullday_leaves->employee->id;
        }

        $employees_not_on_halfday_leave = array_diff($free_employee_list , array_intersect($free_employee_list , $users_on_halfday_leave));

        $employees_not_on_fullday_leave = array_diff($free_employee_list , array_intersect($free_employee_list , $users_on_fullday_leave));

        /* if any employee is on leave on that day */
        $employee_lists = User::allEmployees()->where('company_id', $companyId)->select('id', 'name')->whereIn('id', $free_employee_list)->get();

        $employee = User::allEmployees()->where('company_id', $companyId)->select('id', 'name')->whereIn('id', $employees_not_on_fullday_leave)->whereIn('id', $employees_not_on_halfday_leave)->get();

        if($this->getCartCompanyDetail()->employee_selection == 'enabled')
        {
            $i = 0;
            foreach($employee_lists as $employee_list)
            {
                $user_schedule = $this->checkUserSchedule($employee_list->id, $request->date);

                if($this->getCartCompanyDetail()->disable_slot == 'enabled')
                {
                    foreach ($employee as $key => $employees) {

                        if($user_schedule==true) {
                            $select_user .= '<option value="'.$employees->id.'">'.$employees->name.'</option>';
                            $i++;
                            $select_user .= '</select>';
                        }
                        if($i>0) {
                            return response(Reply::dataOnly(['continue_booking'=>'yes', 'select_user'=>$select_user]));
                        }
                        return response(Reply::dataOnly(['continue_booking'=>'no']));
                    }
                }
                else
                {
                    foreach ($employee as $key => $employees) {
                        $select_user .= '<option value="'.$employees->id.'">'.$employees->name.'</option>';
                    }
                    $select_user .= '</select>';
                    return response(Reply::dataOnly(['continue_booking'=>'yes', 'select_user'=>$select_user]));
                }
            }
        }

        /* if no employee found of that particular service */
        if(empty($free_employee_list)) {
            if($this->getCartCompanyDetail()->multi_task_user=='enabled') {
                /* give dropdown of all users */
                if($this->getCartCompanyDetail()->employee_selection=='enabled') {
                    $employee_lists = User::allEmployees()->select('id', 'name')->whereIn('id', $all_users_of_particular_services)->get();
                    foreach ($employee_lists as $key => $employee_list) {
                        $select_user .= '<option value="'.$employee_list->id.'">'.$employee_list->name.'</option>';
                    }
                    $select_user .= '</select>';
                    return response(Reply::dataOnly(['continue_booking'=>'yes', 'select_user'=>$select_user]));
                }
            }
            else {
                /* block booking here  */
                return response(Reply::dataOnly(['continue_booking'=>'no']));
            }
        }

        /* if multitasking and allow employee selection is enabled */
        if($this->getCartCompanyDetail()->multi_task_user=='enabled') {
            /* give dropdown of all users */
            if($this->getCartCompanyDetail()->employee_selection=='enabled') {
                $employee_lists = User::allEmployees()->select('id', 'name')->whereIn('id', $all_users_of_particular_services)->get();
                foreach ($employee_lists as $key => $employee_list) {
                    $select_user .= '<option value="'.$employee_list->id.'">'.$employee_list->name.'</option>';
                }
                $select_user .= '</select>';
                return response(Reply::dataOnly(['continue_booking'=>'yes', 'select_user'=>$select_user]));
            }
        }

        /* select of all remaining employees */
        $employee_lists = User::allEmployees()->select('id', 'name')->whereIn('id', $free_employee_list)->get();

        if($this->getCartCompanyDetail()->employee_selection=='enabled') {
            $i = 0;
            foreach ($employee_lists as $key => $employee_list) {
                $user_schedule = $this->checkUserSchedule($employee_list->id, $request->date);
                if($this->getCartCompanyDetail()->disable_slot=='enabled') {
                    // call function which will see employee schedules
                    if($user_schedule==true) {
                        $select_user .= '<option value="'.$employee_list->id.'">'.$employee_list->name.'</option>';
                        $i++;
                    }
                }
                else {
                    if($user_schedule==true) {
                        $select_user .= '<option value="'.$employee_list->id.'">'.$employee_list->name.'</option>';
                        $i++;
                    }
                }
            }
            $select_user .= '</select>';
            if($i>0) {
                return response(Reply::dataOnly(['continue_booking'=>'yes', 'select_user'=>$select_user]));
            }
            return response(Reply::dataOnly(['continue_booking'=>'no']));
        }

        $user_check_array = array();
        foreach ($employee_lists as $key => $employee_list) {
            // call function which will see employee schedules
            $user_schedule = $this->checkUserSchedule($employee_list->id, $request->date);
            if($user_schedule==true) {
                $user_check_array[] = $employee_list->id;
            }
        }

        if(empty($user_check_array)) {
            return response(Reply::dataOnly(['continue_booking'=>'no']));
        }
    }


    public function checkUserSchedule($userid, $dateTime)
    {
        $new_booking_start_time = Carbon::parse($dateTime)->format('Y-m-d H:i');
        $time = $this->calculateCartItemTime();
        $end_time1 = Carbon::parse($dateTime)->addMinutes($time-1);

        $userBooking =  Booking::whereIn('status', ['pending','in progress', 'approved'])->with('users')->whereHas('users', function($q)use($userid){
            $q->where('user_id', $userid);
        });
        $bookings = $userBooking->get();

        if($userBooking->count()>0) {
            foreach ($bookings as $key => $booking) {
                /* previous booking start date and time */
                $start_time = Carbon::parse($booking->date_time)->format('Y-m-d H:i');
                $booking_time = $this->calculateBookingTime($booking->id);
                $end_time = $booking->date_time->addMinutes($booking_time-1);

                if( Carbon::parse($new_booking_start_time)->between($start_time, Carbon::parse($end_time)->format('Y-m-d H:i'), true) || Carbon::parse($start_time)->between($new_booking_start_time, Carbon::parse($end_time1)->format('Y-m-d H:i'), true) ) {
                    return false;
                }
            }
        }
        return true;
    }

    public function calculateBookingTime($booking_id)
    {
        $booking_time_type =  $this->getCartCompanyDetail()->booking_time_type;
        $booking_items = BookingItem::with('businessService')->where('booking_id', $booking_id)->get();
        $time = 0;
        $total_time = 0;
        $max = 0;
        $min = 0;
        foreach ($booking_items as $key => $item)
        {
            if ($item->businessService->time_type == 'minutes') {
                $time = $item->businessService->time;
            } elseif ($item->businessService->time_type == 'hours') {
                $time = $item->businessService->time * 60;
            } elseif ($item->businessService->time_type == 'days') {
                $time = $item->businessService->time * 24 * 60;
            }

            $total_time += $time;

            if ($key == 0) { $min = $time; $max = $time; }
            if ($time < $min) { $min = $time; }
            if ($time > $max) { $max = $time; }
        }

        if ($booking_time_type == 'sum') { return $total_time; }
        elseif ($booking_time_type == 'max') { return $max; }
        elseif ($booking_time_type == 'min') { return $min; }
        elseif ($booking_time_type == 'avg') { return $total_time / $booking_items->count();}
    }

    public function calculateCartItemTime()
    {
        $booking_time_type =  $this->getCartCompanyDetail()->booking_time_type;

        $products = json_decode(request()->cookie('products'), true);

        foreach ($products as $key => $product) {
            $bookingIds[] = $key;
        }

        $booking_items = BusinessService::whereIn('id', $bookingIds)->get();
        $time = 0;
        $total_time = 0;
        $max = 0;
        $min = 0;

        foreach ($booking_items as $key => $booking_item) {

            if ($booking_item->time_type == 'minutes') {
                $time = $booking_item->time;
            } elseif ($booking_item->time_type == 'hours') {
                $time = $booking_item->time * 60;
            } elseif ($booking_item->time_type == 'days') {
                $time = $booking_item->time * 24 * 60;
            }

            $total_time += $time;

            if ($key == 0) { $min = $time; $max = $time; }
            if ($time < $min) {  $min = $time; }
            if ($time > $max) { $max = $time; }
        }

        if ($booking_time_type == 'sum') { return $total_time; }
        elseif ($booking_time_type == 'max') { return $max; }
        elseif ($booking_time_type == 'min') { return $min; }
        elseif ($booking_time_type == 'avg') { return $total_time / $booking_items->count('id'); }
    }

    public function grabDeal(Request $request)
    {
        $deal = [
            "dealId" => $request->dealId,
            "dealPrice" => $request->dealPrice,
            "dealName" => $request->dealName,
            "dealQuantity" => $request->dealQuantity,
            "dealUnitPrice" => $request->dealUnitPrice,
            "dealCompanyName" => $request->dealCompanyName,
            "dealMaxQuantity" => $request->dealMaxQuantity,
            "dealCompanyId" => $request->dealCompanyId,
        ];

        return response([
            'status' => 'success',
            'message' => 'deal added successfully',
            ])->cookie('deal', json_encode($deal));
    }

    public function suggestEmployee($date)
    {
        /* check for all employee of that service, of that particular location  */
        $dateTime = $date;

        [$service_ids, $service_names] = Arr::divide(json_decode(request()->cookie('products'), true));

        $user_lists = BusinessService::with('users')->whereIn('id', $service_ids)->get();

        $all_users_of_particular_services = array();
        foreach ($user_lists as $user_list) {
            foreach ($user_list->users as $user) {
                $all_users_of_particular_services[] = $user->id;
            }
        }

        /* if no empolyee for that particular service is found then allow booking with null employee assignment  */
        if (empty($all_users_of_particular_services)) {
            return '';
        }

          /* Employee schedule: */
          $day = $dateTime->format('l');
          $time = $dateTime->format('H:i:s');
          $date = $dateTime->format('Y-m-d');

          /* Check for employees working on that day: */
          $employeeWorking = EmployeeSchedule::with('employee')->where('days', $day)
          ->whereTime('start_time', '<=', $time)->whereTime('end_time', '>=', $time)
          ->where('is_working', 'yes')->whereIn('employee_id', $all_users_of_particular_services)->get();

          $working_employee = array();
          foreach($employeeWorking as $employeeWorkings) {
                  $working_employee[] = $employeeWorkings->employee->id;
          }

        $assigned_user_list_array = array();
        $assigned_users_list =  Booking::with('users')
            ->where('date_time', $dateTime)
            ->get();

        foreach ($assigned_users_list as $key => $value) {
            foreach ($value->users as $key1 => $value1) {
                $assigned_user_list_array[] = $value1->id;
            }
        }

        $free_employee_list = array_diff($working_employee, array_intersect($working_employee, $assigned_user_list_array));

        /* Leave: */

        /* check for half day*/
        $halfday_leave = Leave::with('employee')->whereDate('start_date', '<=', $date)
        ->whereDate('end_date', '>=', $date)->whereTime('start_time', '<=', $time)
        ->whereTime('end_time', '>=', $time)->where('leave_type', 'Half day')->where('status', 'approved')->get();

        $users_on_halfday_leave = array();
        foreach($halfday_leave as $halfday_leaves) {
                $users_on_halfday_leave[] = $halfday_leaves->employee->id;
        }

        /* check for full day*/
        $fullday_leave = Leave::with('employee')->whereDate('start_date', '<=', $date)
        ->whereDate('end_date', '>=', $date)->where('leave_type', 'Full day')->where('status', 'approved')->get();

        $users_on_fullday_leave = array();
        foreach($fullday_leave as $fullday_leaves) {
                $users_on_fullday_leave[] = $fullday_leaves->employee->id;
        }

        $employees_not_on_halfday_leave = array_diff($free_employee_list , array_intersect($free_employee_list , $users_on_halfday_leave));

        $employees_not_on_fullday_leave = array_diff($free_employee_list , array_intersect($free_employee_list , $users_on_fullday_leave));

        $companyId = Role::select('company_id')->where('id', auth()->user()->role->id)->first()->company_id;
        $company = Company::where('id', $companyId)->first();

        /* if any employee is on leave on that day */
            if($this->getCartCompanyDetail()->employee_selection=='enabled') {

                return User::allEmployees()->select('id', 'name')->whereIn('id', $employees_not_on_fullday_leave)->whereIn('id', $employees_not_on_halfday_leave)->get();

            }

        /* if no employee found then return allow booking with no employee assignment   */
        if (empty($free_employee_list)) {
            if ($this->getCartCompanyDetail()->multi_task_user == 'enabled') {
                /* give single users */
                return User::select('id', 'name')->whereIn('id', $all_users_of_particular_services)->first()->id;
            }
        }

        /* select of all remaining employees */
        $users = User::select('id', 'name')->whereIn('id', $free_employee_list);
        if ($this->settings->disable_slot == 'enabled') {
            foreach ($users->get() as $key => $employee_list) {
                // call function which will see employee schedules
                $user_schedule = $this->checkUserSchedule($employee_list->id, $date);
                if ($user_schedule == true) {
                    return $employee_list->id;
                }
            }
        }

        return $users->first()->id;
    }

    public function allDeals(Request $request)
    {

        if($request->ajax()){
            $deals = Deal::withoutGlobalScope('company')
                    ->activeCompany()
                    ->with([
                        'company' => function($q) { $q->withoutGlobalScope('company'); },
                        'location' => function($q) { $q->withoutGlobalScope('company'); },
                        'services' => function($q) { $q->withoutGlobalScope('company'); },
                    ]);

            if(!is_null($request->locations)) {
                $locations = explode(",",$request->locations);
                $deals->WhereHas('location', function($query) use($locations) {
                    $query->WhereIn('id', $locations);
                });
            }

            if(!is_null($request->categories)) {
                $categories = explode(",",$request->categories);
                $deals->WhereHas('services.businessService.category', function($query) use($categories) {
                    $query->WhereIn('id', $categories);
                });
            }

            if(!is_null($request->companies)) {
                $companies = explode(",",$request->companies);
                $deals->WhereIn('company_id', $companies);
            }

            if(!is_null($request->price)) {
                $prices = $request->price;

                $firstPrice = explode('-', array_shift($prices));
                $low = $firstPrice[0];
                $high = $firstPrice[1];

                $priceArr = [];
                foreach ($prices as $price) {
                    $priceArr[] = [
                        explode('-', $price)[0],
                        explode('-', $price)[1],
                    ];
                }

                $deals = $deals->whereBetween('deal_amount', [$low,$high]);

                foreach ($priceArr as $price) {
                    $deals = $deals->orWhereBetween('deal_amount', [$price[0], $price[1]]);
                }
            }

            if(!is_null($request->discounts)) {
                $discounts = $request->discounts;

                $firstDiscount = explode('-', array_shift($discounts));
                $low = $firstDiscount[0];
                $high = $firstDiscount[1];

                $discountArr = [];
                foreach ($discounts as $discount) {
                    $discountArr[] = [
                        explode('-', $discount)[0],
                        explode('-', $discount)[1],
                    ];
                }

                $deals = $deals->where('discount_type', 'percentage')->whereBetween('percentage', [$low,$high]);

                foreach ($discountArr as $discount) {

                    $deals = $deals->where('discount_type', 'percentage')->orWhereBetween('percentage', [$discount[0], $discount[1]]);
                }
            }

            if(!is_null($request->sort_by)) {
                if($request->sort_by=='newest') {
                    $deals->orderBy('id', 'DESC');
                }
                elseif($request->sort_by=='low_to_high') {
                    $deals->orderBy('deal_amount');
                }
                elseif($request->sort_by=='high_to_low') {
                    $deals->orderBy('deal_amount', 'DESC');
                }
            }

            $deals = $deals->paginate(10);

            $view = view('front.filtered_deals', compact('deals'))->render();
            return Reply::dataOnly(['view' => $view, 'deal_count' => $deals->count(), 'deal_total' => $deals->total()]);
        }

        $companies = Company::withoutGlobalScope('company')->get();
        $categories = Category::withoutGlobalScope('company')->has('services', '>', 0)->get();
        $locations = Location::withoutGlobalScope('company')->active()->get();
        return view('front.all_deals', compact('locations', 'categories', 'companies'));
    }

    public function AllServices(Request $request)
    {

        if($request->ajax())
        {
            $services = BusinessService::withoutGlobalScope('company')
                ->activeCompany()
                ->with([
                    'location' => function($q) { $q->withoutGlobalScope('company'); } ,
                    'category' => function($q) { $q->withoutGlobalScope('company'); } ,
                    'company' => function($q) { $q->withoutGlobalScope('company'); }
                ])->active();

            if(!is_null($request->service_name)) {
                $services = $services->where('name', 'like', '%'.$request->service_name.'%');
            }

            if(is_null($request->company_id) && !is_null($request->term)) {
                $services = $services->where('name', 'like', '%'.$request->term.'%');
            }

            if(!is_null($request->company_id)) {
                $company_id = $request->company_id;
                $services = $services->whereHas('company', function($q) use($company_id){
                    $q->where('id', $company_id);
                });
            }

            if(!is_null($request->locations)) {
                $locations = explode(",",$request->locations);
                $services->whereIn('location_id', $locations);
            }

            if(!is_null($request->categories)) {
                $categories = explode(",",$request->categories);
                $services->whereIn('category_id', $categories);
            }

            if(!is_null($request->companies)) {
                $companies = explode(",",$request->companies);
                $services->whereIn('company_id', $companies);
            }

            if(!is_null($request->price)) {
                $prices = $request->price;

                $firstPrice = explode('-', array_shift($prices));
                $low = $firstPrice[0];
                $high = $firstPrice[1];

                $priceArr = [];
                foreach ($prices as $price) {
                    $priceArr[] = [
                        explode('-', $price)[0],
                        explode('-', $price)[1],
                    ];
                }

                $services = $services->whereBetween('price', [$low,$high]);

                foreach ($priceArr as $price) {
                    $services = $services->orWhereBetween('price', [$price[0], $price[1]]);
                }
            }

            if(!is_null($request->discounts)) {
                $discounts = $request->discounts;

                $firstDiscount = explode('-', array_shift($discounts));
                $low = $firstDiscount[0];
                $high = $firstDiscount[1];

                $discountArr = [];
                foreach ($discounts as $discount) {
                    $discountArr[] = [
                        explode('-', $discount)[0],
                        explode('-', $discount)[1],
                    ];
                }

                $services = $services->where('discount_type', 'percent')->whereBetween('discount', [$low,$high]);

                foreach ($discountArr as $discount) {
                    $services = $services->where('discount_type', 'percent')->orWhereBetween('discount', [$discount[0], $discount[1]]);
                }
            }

            if(!is_null($request->sort_by)) {
                if($request->sort_by=='newest') {
                    $services->orderBy('id', 'DESC');
                }
                elseif($request->sort_by=='low_to_high') {
                    $services->orderBy('net_price');
                }
                elseif($request->sort_by=='high_to_low') {
                    $services->orderBy('net_price', 'DESC');
                }
            }

            $services = $services->paginate(10);

            $view = view('front.filtered_services', compact('services'))->render();
            return Reply::dataOnly(['view' => $view, 'service_count' => $services->count(), 'service_total' => $services->total()]);

        } /* end of ajax */

        $company_id = !is_null($request->company_id) ? $request->company_id : '';

        $category_id = '';
        if($request->category_id && $request->category_id != 'all'){
            $category_id = Category::where('slug', $request->category_id)->first();
            if(!$category_id) {
                abort(404);
            }

            $category_id = $category_id->id;
        }

        $categories = Category::withoutGlobalScope('company')->has('services', '>', 0)->withCount(['services' => function($q) {
            $q->withoutGlobalScope('company');
        }])
        ->get();

        return view('front.all_services', compact('categories', 'category_id', 'company_id'));
    }

    public function allCoupons(Request $request)
    {
        $coupons = Coupon::withoutGlobalScope('company')
            ->with(['company' => function($q) {
                    $q->withoutGlobalScope('company');
                }
            ]);

        if($request->ajax())
        {
            if(!is_null($request->companies)) {
                $companies = explode(",",$request->companies);
                $coupons->WhereIn('company_id', $companies);
            }
            if(!is_null($request->discounts)) {
                $price = explode('-', $request->discounts[0]);
                $low = $price[0];
                $high = $price[1];
                $coupons->whereBetween('percent',array($low,$high));
            }
            if(!is_null($request->sort_by)) {
                if($request->sort_by=='newest') {
                    $coupons->orderBy('id', 'DESC');
                }
                elseif($request->sort_by=='low_to_high') {
                    $coupons->orderBy('percent');
                }
                elseif($request->sort_by=='high_to_low') {
                    $coupons->orderBy('percent', 'DESC');
                }
            }

            $coupons = $coupons->paginate(10);
            $view = view('front.filtered_coupons', compact('coupons'))->render();
            return Reply::dataOnly(['view' => $view, 'coupon_total' => $coupons->total() , 'coupon_count' => $coupons->count()]);
        }

        $companies = Company::withoutGlobalScope('company')->get();
        $coupons = $coupons->paginate(10);
        return view('front.all_coupons', compact('coupons', 'companies'));
    }

    public function getCouponCompany($code)
    {
        $coupon = Coupon::where('code', $code)->first();
        return !is_null($coupon) ? $coupon->company_id : null ;
    }

    /* return all the detail of company added to cart */
    public function getCartCompanyDetail()
    {
        $products = json_decode(request()->cookie('products'), true);

        $companyIds = [];
        foreach ($products as $key => $product) {
            $companyIds[] = $product['companyId'];
        }

        if(sizeof($companyIds) > 0) {
            return Company::where('id', $companyIds[0])->first();
        }
        return null;
    }

    public function globalSearch(Request $request)
    {

        $search =  $request->term;
        $location = !is_null($request->location) ? $request->location : '';
        $filterItem = [];

        $categories = Category::where('name','LIKE',"%{$search}%")->orderBy('id','DESC')->limit(2)->get();

        $services = BusinessService::withoutGlobalScope('company')
        ->activeCompany()
        ->with([
            'location' => function($q) { $q->withoutGlobalScope('company'); }
        ])
        ->Where('location_id', $location)
        ->where('name','LIKE',"%{$search}%")
        ->orderBy('id','DESC')
        ->limit(2)->get();

        $deals = Deal::withoutGlobalScope('company')
        ->activeCompany()
        ->with([
            'location' => function($q) { $q->withoutGlobalScope('company'); }
        ])
        ->WhereHas('location', function($query) use($location) {
            $query->Where('id', $location);
        })
        ->where('title','LIKE',"%{$search}%")
        ->orderBy('id','DESC')
        ->limit(2)->get();


        $companies = Company::withoutGlobalScope('company')
        ->active()
        ->where('company_name','LIKE',"%{$search}%")
        ->orderBy('id','DESC')
        ->limit(2)->get();

        if(!$categories->isEmpty()) {
            foreach($categories as $category) {
                $filteredRes['title']= $category->name;
                $filteredRes['image']= $category->category_image_url;
                $filteredRes['url']= url($category->slug.'/services');
                $filteredRes['category']= 'Category';
                $filterItem[] = $filteredRes;
            }
        }

        if(!$services->isEmpty()) {
            foreach($services as $service) {
                $filteredRes['title']= $service->name;
                $filteredRes['image']= $service->service_image_url;
                $filteredRes['url']= $service->service_detail_url;
                $filteredRes['category']= 'Service';
                $filterItem[] = $filteredRes;
            }
        }

        if(!$deals->isEmpty()) {
            foreach($deals as $deal) {
                $filteredRes['title']= $deal->title;
                $filteredRes['image']= $deal->deal_image_url;
                $filteredRes['url']= $deal->deal_detail_url;
                $filteredRes['category']= 'Deal';
                $filterItem[] = $filteredRes;
            }
        }

        if(!$companies->isEmpty()) {
            foreach($companies as $company) {
                $filteredRes['title']= $company->company_name;
                $filteredRes['image']= $company->logo_url;
                $filteredRes['url']= route('front.search', ['c' => $company->id]);
                $filteredRes['category']= 'Company';
                $filterItem[] = $filteredRes;
            }
        }
        return json_encode($filterItem);
    }

    public function register()
    {
        return view('front.register');
    }

    public function email()
    {
        return view('front.email_verification');
    }

    public function store_company(RegisterCompany $request)
    { 
        if(request()->ajax())
        {
            if($this->googleCaptchaSettings->status == 'active' && $this->googleCaptchaSettings->v3_status == 'active')
            {
                $url = 'https://www.google.com/recaptcha/api/siteverify';
                $remoteip = $_SERVER['REMOTE_ADDR'];
                $data = [
                        'secret' => $this->googleCaptchaSettings->v3_secret_key,
                        'response' => $request->get('recaptcha'),
                        'remoteip' => $remoteip
                    ];
                $options = [
                        'http' => [
                        'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                        'method' => 'POST',
                        'content' => http_build_query($data)
                        ]
                    ];
                $context = stream_context_create($options);
                $result = file_get_contents($url, false, $context);
                $resultJson = json_decode($result);
                if ($resultJson->success != true) {
                    return back()->withErrors(['captcha' => 'ReCaptcha Error']);
                }
                if ($resultJson->score < 0.3) {
                    return back()->withErrors('captcha', 'Your google captcha score is poor..!');
                }
            }

            $data = [
                'company_name' => $request->business_name,
                'company_email' => $request->email,
                'company_phone' => $request->contact,
                'address' => $request->address,
                'website' => $request->website,
                'date_format' => 'Y-m-d',
                'time_format' => 'h:i A',
                'timezone' => 'Africa/Johannesburg',
                'currency_id' => Currency::first()->id,
                'locale' =>  Language::first()->language_code,
            ];

            $company = Company::create($data);

            // create admin/employee
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => $request->password,
                'company_id' => $company->id
            ]);
            $user->attachRole(Role::withoutGlobalScope('company')->select('id', 'name')->where(['name' => 'administrator', 'company_id' => $company->id])->first()->id);

            return Reply::success(__('email.verificationLinkSent'));
        }

    }

    public function confirmEmail(Request $request)
    {
        $company = Company::where(['company_email' => Crypt::decryptString($request->email), 'verified' => 'no', 'status' => 'inactive'])->firstOrFail();

        $company->verified = 'yes';
        $company->status = 'active';
        $company->save();

        $company = User::with('company')->where('email', Crypt::decryptString($request->email))->first();

        $superadmin = User::with('company', 'roles')->whereHas('roles', function($q){
            $q->where('name', 'superadmin');
        })->first();

        // send welcome email to admin
        $company->notify(new CompanyWelcome($company));

        // send email to superadmin
        $superadmin->notify(new SuperadminNotificationAboutNewAddedCompany($company));

        return view('front/email_verified_success');
    }

    public function pricing()
    {
        $frontFaqsCount = FrontFaq::select('id', 'language_id')->where('language_id', $this->localeLanguage ? $this->localeLanguage->id : null)->count();

        $frontFaqs = FrontFaq::where('language_id', $frontFaqsCount > 0 ? ( $this->localeLanguage ? $this->localeLanguage->id : null ) : null)->get();

        $packages = Package::where('type', Null)->get();
        return view('front.pricing', compact('packages', 'frontFaqs'));
    }

    public function checkDealQuantity($dealId) {
        $deal = Deal::find($dealId);
        $max_order_per_customer = !is_null($deal->max_order_per_customer) ? $deal->max_order_per_customer : 0;

        return $max_order_per_customer;
    }

    public function logout() {
        Auth::logout();
        return redirect('login');
    }

    public function vendorPage(Request $request, $slug)
    {
        $this->company = Company::withoutGlobalScope('company')->whereSlug($slug)
        ->active()->verified()->firstOrFail();
        $this->vendorPage = VendorPage::withoutGlobalScope('company')->where('company_id',$this->company->id)->first();
        $this->bookingTimes = BookingTime::withoutGlobalScope('company')->where('company_id',$this->company->id)->get();
        $this->categories = Category::withoutGlobalScope('company')->has('services', '>', 0)->withCount(['services' => function($q) {
            $q->withoutGlobalScope('company');
        }])
        ->get();

        return view('front.vendor', $this->data);
    }

    public function allCompanyDeals(Request $request, $slug)
    {
        $company = Company::withoutGlobalScope('company')->whereSlug($slug)->firstOrFail();
        if($request->ajax()){
            $this->deals = Deal::withoutGlobalScope('company')->where('company_id',$company->id)
                    ->with([
                        'company' => function($q) { $q->withoutGlobalScope('company'); },
                        'location' => function($q) { $q->withoutGlobalScope('company'); },
                        'services' => function($q) { $q->withoutGlobalScope('company'); },
                    ])->paginate(10);
            $view = view('front.vendor_deals', $this->data)->render();

            return Reply::dataOnly(['view' => $view]);
        }
    }

} /* End of class */
