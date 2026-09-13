<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\AccountTransaction;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function index(Request $request)
    {
        $accounts = Account::query()
            ->when($request->type, fn ($q) => $q->type($request->type))
            ->when($request->q, function ($q) use ($request) {
                $q->where(function ($q) use ($request) {
                    $q->where('name', 'like', "%{$request->q}%")
                        ->orWhere('code', 'like', "%{$request->q}%");
                });
            })
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        // One grouped query for the whole page instead of one balance query
        // per row: each account's TL/USD/EUR balances stay separate, never
        // summed into a single blended number.
        $balancesByAccount = AccountTransaction::whereIn('account_id', $accounts->pluck('id'))
            ->selectRaw("account_id, currency, SUM(CASE WHEN direction = 'debit' THEN amount ELSE -amount END) as balance")
            ->groupBy('account_id', 'currency')
            ->get()
            ->groupBy('account_id');

        return view('accounts.index', compact('accounts', 'balancesByAccount'));
    }

    public function create()
    {
        return view('accounts.create');
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $data['active'] = $request->boolean('active', true);
        $data['code'] = Account::generateCode();

        $account = Account::create($data);

        return redirect()->route('accounts.show', $account)->with('success', 'Cari hesap eklendi.');
    }

    public function show(Request $request, Account $account)
    {
        $transactions = $account->transactions()
            ->with('user')
            ->orderByDesc('transaction_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('accounts.show', compact('account', 'transactions'));
    }

    public function edit(Account $account)
    {
        return view('accounts.edit', compact('account'));
    }

    public function update(Request $request, Account $account)
    {
        $data = $this->validated($request);
        $data['active'] = $request->boolean('active');

        $account->update($data);

        return redirect()->route('accounts.show', $account)->with('success', 'Cari hesap güncellendi.');
    }

    public function destroy(Account $account)
    {
        if ($account->transactions()->exists()) {
            return back()->with('error', 'Bu cari hesaba ait hareketler var, silinemez.');
        }

        $account->delete();

        return redirect()->route('accounts.index')->with('success', 'Cari hesap silindi.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'type' => ['required', Rule::in(array_keys(Account::TYPES))],
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'tax_office' => ['nullable', 'string', 'max:255'],
            'tax_no' => ['nullable', 'string', 'max:50'],
            'note' => ['nullable', 'string'],
            'active' => ['nullable', 'boolean'],
        ]);
    }
}
