<?php

namespace App\Http\Controllers\SuperAdmin;

use App\User;
use App\Booking;
use App\Category;
use App\Company;
use Carbon\Carbon;
use Froiden\Envato\Helpers\Reply;
use App\Currency;
use Illuminate\Http\Request;
use App\Http\Controllers\SuperAdminBaseController;

class ShowDashboard extends SuperAdminBaseController
{
    public function __construct()
    {
        parent::__construct();
        view()->share('pageTitle', __('menu.dashboard'));
    }

    /**
     * Handle the incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function __invoke(Request $request)
    {
        if(\request()->ajax())
        {

            $startDate = Carbon::createFromFormat($this->settings->date_format, $request->startDate)->format('Y-m-d');
            $endDate = Carbon::createFromFormat($this->settings->date_format, $request->endDate)->format('Y-m-d');

            $totalCustomers = User::withoutGlobalScopes()->allCustomers()
            ->whereDate('created_at', '>=', $startDate)
            ->whereDate('created_at', '<=', $endDate)
            ->count();
            $totalVendors = User::withoutGlobalScopes()->allAdministrators()
            ->whereDate('created_at', '>=', $startDate)
            ->whereDate('created_at', '<=', $endDate)
            ->count();

            $totalEarnings = Booking::withoutGlobalScopes()->whereDate('date_time', '>=', $startDate)
            ->whereDate('date_time', '<=', $endDate)
            ->where('payment_status', 'completed')
            ->sum('amount_to_pay');

            $activeCompanies = Company::where('status', '=', 'active')
            ->whereDate('created_at', '>=', $startDate)
            ->whereDate('created_at', '<=', $endDate)
            ->count();
            $deActiveCompanies = Company::where('status', '=', 'inactive')
            ->whereDate('created_at', '>=', $startDate)
            ->whereDate('created_at', '<=', $endDate)
            ->count();
            // return $totalCustomers;
            return Reply::dataOnly(['status' => 'success', 'totalCustomers' => $totalCustomers, 'totalEarnings' => round($totalEarnings, 2), 'totalVendors' => $totalVendors, 'activeCompanies' => $activeCompanies, 'deActiveCompanies' => $deActiveCompanies,]);
        }


        $this->totalCategories = Category::withoutGlobalScopes()->get()->count();
        $this->todoItemsView = $this->generateTodoView();
        $this->isNotSetExchangeRate = (Currency::where('exchange_rate',null)->where('deleted_at',null)->count()>0);

        return view('superadmin.dashboard.index', $this->data);
    }
}
