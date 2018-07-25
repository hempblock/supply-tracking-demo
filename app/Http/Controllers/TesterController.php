<?php

namespace App\Http\Controllers;

use App\Jobs\PutFarmerNoRevisions;
use App\Models\EtherAccounts;
use App\Models\Farmer;
use App\Models\FarmerFile;
use Illuminate\Support\Facades\DB;
use \Illuminate\Http\Request;
use \Illuminate\Routing\Controller;

class TesterController extends Controller
{

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $testers = auth()->user()->testers()->latest()->get();

        return view('tester.index', compact('testers'))
            ->with('dashboard_params', array('title'=>'Testersrs', 'active_li_main'=>'testers'));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return view('tester.create');
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request $request
     *
     * @return \Illuminate\Http\Response
     * @throws \Exception
     */
    public function store(Request $request)
    {
        try {
            DB::beginTransaction();
                $result = $this->_store($request);
            DB::commit();
        } catch (\Exception $e) {
                DB::rollBack();
            throw $e;
        }
        return $result;
    }

    protected function _store(Request $request, Tester $tester = null, EtherAccounts $ethAccount = null)
    {
        if($doInsert = (is_null($tester))){
            $tester = new Tester();
            $tester->uuid = auth()->user()->uuid;
        }

        /* separated accounts table */
        if(empty($ethAccount) and $doInsert){
            // auto generated account
            $ethAccount = new EtherAccounts();
            $ethAccount->save();
            $farmer->eth_address = $ethAccount;
        }
        /* main farmer record */
        $tester->firstname  = array_get($request->input('tester'), 'firstname');
        $tester->lastname   = array_get($request->input('tester'), 'lastname');
        $tester->email      = array_get($request->input('tester'), 'email');
        $tester->address    = array_get($request->input('tester'), 'address');

        /* google map api */
        if(is_array($gApi = $request->input('googleMapAPI',null))){
            $farmer->gm_lat         = $gApi['lat'];
            $farmer->gm_lon         = $gApi['lon'];
            $farmer->gm_place_id    = $gApi['placeID'];
        }

        /* file manager (existing files)*/
        $existingFiles = $request->input('existingFile', []);
        foreach ($existingFiles as $fileID => $fileOptions){
            if ($fileOptions['delete'] == 'y') {
                $farmer->setForDeletion($fileID);
            } else {
                $farmer->setForRenaming($fileID, $fileOptions['name']);
            }
        }

        /* file manager (new files)*/
        $fileNames = $request->input('docsName',[]);
        foreach (($files = $request->files->get('docs', [])) as $idxFile => $file) {
            $fileName = trim(array_get($fileNames, $idxFile, ''));
            $farmer->newUpload($file, $fileName);

        }

        $props = $request->input('farmer_props', []);
        if (!empty($props)) {
            foreach ($props['key'] as $k => $optionName) {
                if (empty($optionName))
                    continue;

                $optionValue = $props['value'][$k];
                $farmer->newProperty($optionName, $optionValue);
            }
        }

        $tester->save();
        dispatch(new PutTesterNoRevisions($tester));

        return response()->redirectTo(route('tester.show', $tester));

    }

    /**
     * Display the specified resource.
     *
     * @param  Tester $farmer
     *
     * @return \Illuminate\Http\Response
     */
    public function show(Tester $tester)
    {
        return view('tester.show', compact('tester'));
    }

    /**
     * @param Request $request
     * @param Tester  $farmer
     *
     * @return \Illuminate\Http\RedirectResponse
     * @throws \Exception
     */
    public function update(Request $request, Tester $tester)
    {
        try {
            DB::beginTransaction();
            $this->_store($request, $tester);
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }

        return redirect()->route('tester.show',$tester-er);
    }

    public function hackUpdate(Request $request, Tester $testerser) {
        $validatedData = $request->validate([
            'firstname' => 'required',
            'lastname' => 'required',
            'email' => 'required|email|unique:testers,email,'.$tester->id,
            'address' => 'required',
        ]);
        $tester->fill($validatedData);
        $tester->save();
        return redirect()
            ->route('tester.show', $tester)
            ->with('message', 'Data hacked!!!');
    }

    public function download($tester, $file){

        $filePath = storage_path(Tester::storageLocation($tester, false) . $file);

        $file = TesterFile::where('eth_address',$tester)
            ->where('sha512', $file)
            ->firstOrFail();

        if(\Illuminate\Support\Facades\File::exists($filePath)) {
            return response()->download(
                $filePath,
                implode('.',[$file->filename , $file->extension])
            );
        } else {
            abort(404);
        }
    }


    /**
     * This is a resource update which is a HTTP PUT METHOD.
     * @param  Request $request
     * @param  Tester $tester
     *
     * @return \Illuminate\Http\Response
     */
    public function edit(Request $request, Tester $tester)
    {
        return view('tester.edit', compact('tester'));
    }

    public function checkDataIdentity(Tester $tester)
    {
        $dataFromDB = Tester::blockChainFormat($tester);
        if ($tester->tx_test) {
            $tx = app('eth')->eth_getTransactionByHash($tester->tx_test);
            $data = app('eth')->decodeData($tx['input'], '@setGrower', 'data');
            $dataFromBlockchain = $data['data']['arg_1_string'];
            return response()
                ->json(['equal' => compare_data($dataFromDB, $dataFromBlockchain)]);
        }
        return response()->json(['error' => __('Something goes wrong')]);
    }
