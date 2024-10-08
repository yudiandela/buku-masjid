<?php

namespace App\Http\Controllers;

use App\Http\Requests\Transactions\CreateRequest;
use App\Http\Requests\Transactions\UpdateRequest;
use App\Models\BankAccount;
use App\Models\Category;
use App\Transaction;
use Illuminate\Http\Request;

class TransactionsController extends Controller
{
    public function index()
    {
        $editableTransaction = null;
        $yearMonth = $this->getYearMonth();
        $date = request('date');
        $year = (int) request('year', date('Y'));
        $month = request('month', date('m'));
        if (!isset(get_months()[$month])) {
            $month = date('m');
        }
        $startDate = $year.'-'.$month.'-01';

        $transactions = $this->getTansactions($yearMonth);

        $categories = $this->getCategoryList()->prepend('-- '.__('transaction.no_category').' --', 'null');
        $bankAccounts = BankAccount::where('is_active', BankAccount::STATUS_ACTIVE)->pluck('name', 'id')
            ->prepend(__('transaction.cash'), 'null');

        if (in_array(request('action'), ['edit', 'delete']) && request('id') != null) {
            $editableTransaction = Transaction::find(request('id'));
            $categories = $categories->skip(1);
            $bankAccounts = $bankAccounts->skip(1);
        }

        $incomeTotal = $this->getIncomeTotal($transactions);
        $spendingTotal = $this->getSpendingTotal($transactions);

        return view('transactions.index', compact(
            'transactions', 'editableTransaction',
            'yearMonth', 'month', 'year', 'categories',
            'incomeTotal', 'spendingTotal',
            'startDate', 'date', 'bankAccounts'
        ));
    }

    public function create(Request $request)
    {
        $categories = collect([]);

        if (in_array(request('action'), ['add-income'])) {
            $categories = Category::orderBy('name')
                ->where('color', config('masjid.income_color'))
                ->where('status_id', Category::STATUS_ACTIVE)
                ->pluck('name', 'id');
        }

        if (in_array(request('action'), ['add-spending'])) {
            $categories = Category::orderBy('name')
                ->where('color', config('masjid.spending_color'))
                ->where('status_id', Category::STATUS_ACTIVE)
                ->pluck('name', 'id');
        }
        $bankAccounts = BankAccount::where('is_active', BankAccount::STATUS_ACTIVE)->pluck('name', 'id');

        return view('transactions.create', compact('categories', 'bankAccounts'));
    }

    public function store(CreateRequest $transactionCreateForm)
    {
        $transaction = $transactionCreateForm->save();

        if ($transaction['in_out']) {
            flash(__('transaction.income_added'), 'success');
        } else {
            flash(__('transaction.spending_added'), 'success');
        }

        return redirect()->route('transactions.index', [
            'month' => $transaction->month, 'year' => $transaction->year,
        ]);
    }

    public function update(UpdateRequest $transactionUpateForm, Transaction $transaction)
    {
        $this->authorize('update', $transaction);

        $transaction = $transactionUpateForm->save();

        flash(__('transaction.updated'), 'success');

        if ($referencePage = $transactionUpateForm->get('reference_page')) {
            if ($referencePage == 'category') {
                if ($transaction->category) {
                    return redirect()->route('categories.show', [
                        $transaction->category_id,
                        'start_date' => $transactionUpateForm->get('start_date'),
                        'end_date' => $transactionUpateForm->get('end_date'),
                        'book_id' => $transactionUpateForm->get('book_id'),
                        'query' => $transactionUpateForm->get('query'),
                    ]);
                }
            }
        }

        return redirect()->route('transactions.index', [
            'month' => $transaction->month,
            'year' => $transaction->year,
            'category_id' => $transactionUpateForm->get('queried_category_id'),
            'query' => $transactionUpateForm->get('query'),
        ]);
    }

    public function destroy(Transaction $transaction)
    {
        $this->authorize('delete', $transaction);

        request()->validate(['transaction_id' => 'required']);
        if (request('transaction_id') == $transaction->id && $transaction->delete()) {
            flash(__('transaction.deleted'), 'warning');

            if ($referencePage = request('reference_page')) {
                if ($referencePage == 'category') {
                    return redirect()->route('categories.show', [
                        $transaction->category_id,
                        'start_date' => request('start_date'),
                        'end_date' => request('end_date'),
                        'book_id' => request('queried_book_id'),
                        'query' => request('query'),
                    ]);
                }
            }

            return redirect()->route('transactions.index', [
                'month' => $transaction->month, 'year' => $transaction->year,
            ]);
        }

        flash(__('transaction.undeleted'), 'error');

        return back();
    }
}
