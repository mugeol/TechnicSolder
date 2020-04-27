<?php namespace App\Http\Controllers;

use App\Build;
use App\Client;
use App\Key;
use App\Mod;
use App\Modpack;
use App\Modversion;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Response;

class ApiController extends Controller
{

    public function __construct()
    {
        $this->middleware('cors');

        /* This checks the client list for the CID. If a matching CID is found, all caching will be ignored
           for this request */

        if (Cache::has('clients')) {
            $clients = Cache::get('clients');
        } else {
            $clients = Client::all();
            Cache::put('clients', $clients, now()->addMinutes(1));
        }

        if (Cache::has('keys')) {
            $keys = Cache::get('keys');
        } else {
            $keys = Key::all();
            Cache::put('keys', $keys, now()->addMinutes(1));
        }

        $input_cid = Request::input('cid');
        if (!empty($input_cid)) {
            foreach ($clients as $client) {
                if ($client->uuid == $input_cid) {
                    $this->client = $client;
                }
            }
        }

        $input_key = Request::input('k');
        if (!empty($input_key)) {
            foreach ($keys as $key) {
                if ($key->api_key == $input_key) {
                    $this->key = $key;
                }
            }
        }

    }

    public function getIndex()
    {
        return response()->json([
            'api' => 'TechnicSolder',
            'version' => SOLDER_VERSION,
            'stream' => SOLDER_STREAM
        ]);
    }

    public function getModpack($modpack = null, $build = null)
    {
        if (empty($modpack)) {
            if (Request::has('include')) {
                $include = Request::input('include');
                switch ($include) {
                    case "full":
                        $modpacks = $this->fetchModpacks();
                        $m_array = [];
                        foreach ($modpacks['modpacks'] as $slug => $name) {
                            $modpack = $this->fetchModpack($slug);
                            $m_array[$slug] = $modpack;
                        }
                        $response = [];
                        $response['modpacks'] = $m_array;
                        $response['mirror_url'] = $modpacks['mirror_url'];
                        return response()->json($response);
                        break;
                }
            } else {
                return response()->json($this->fetchModpacks());
            }
        } else {
            if (empty($build)) {
                return response()->json($this->fetchModpack($modpack));
            } else {
                return response()->json($this->fetchBuild($modpack, $build));
            }
        }
    }

    public function getMod($mod = null, $version = null)
    {
        if (empty($mod)) {
            $response = [];
            if (Cache::has('modlist') && empty($this->client) && empty($this->key)) {
                $response['mods'] = Cache::get('modlist');
            } else {
                $response['mods'] = [];
                foreach (Mod::all() as $mod) {
                    $response['mods'][$mod->name] = $mod->pretty_name;
                }
                //usort($response['mod'], function($a, $b){return strcasecmp($a['name'], $b['name']);});
                Cache::put('modlist', $response['mods'], now()->addMinutes(5));
            }
            return response()->json($response);
        } else {
            if (Cache::has('mod.' . $mod)) {
                $mod = Cache::get('mod.' . $mod);
            } else {
                $modname = $mod;
                $mod = Mod::where('name', '=', $mod)->first();
                Cache::put('mod.' . $modname, $mod, now()->addMinutes(5));
            }

            if (empty($mod)) {
                return response()->json(['error' => 'Mod does not exist']);
            }

            if (empty($version)) {
                return response()->json($this->fetchMod($mod));
            }

            return response()->json($this->fetchModversion($mod, $version));
        }
    }

    public function getVerify($key = null)
    {
        if (empty($key)) {
            return response()->json(["error" => "No API key provided."]);
        }

        $key = Key::where('api_key', '=', $key)->first();

        if (empty($key)) {
            return response()->json(["error" => "Invalid key provided."]);
        } else {
            return response()->json(["valid" => "Key validated.", "name" => $key->name, "created_at" => $key->created_at]);
        }
    }


    /* Private Functions */

    private function fetchMod($mod)
    {
        $response = [];

        $response['id'] = $mod->id;
        $response['name'] = $mod->name;
        $response['pretty_name'] = $mod->pretty_name;
        $response['author'] = $mod->author;
        $response['description'] = $mod->description;
        $response['link'] = $mod->link;
        $response['versions'] = [];

        foreach ($mod->versions as $version) {
            array_push($response['versions'], $version->version);
        }

        return $response;
    }

    private function fetchModversion($mod, $version)
    {
        $response = [];

        $version = Modversion::where("mod_id", "=", $mod->id)
            ->where("version", "=", $version)->first();

        if (empty($version)) {
            return ["error" => "Mod version does not exist"];
        }

        $response['id'] = $version->id;
        $response['md5'] = $version->md5;
        $response['filesize'] = $version->filesize;
        $response['url'] = Config::get('solder.mirror_url') . 'mods/' . $version->mod->name . '/' . $version->mod->name . '-' . $version->version . '.zip';

        return $response;
    }

    private function fetchModpacks()
    {
        if (Cache::has('modpacks') && empty($this->client) && empty($this->key)) {
            $modpacks = Cache::get('modpacks');
        } else {
            $modpacks = Modpack::all();
            if (empty($this->client) && empty($this->key)) {
                Cache::put('modpacks', $modpacks, now()->addMinutes(5));
            }

        }

        $response = [];
        $response['modpacks'] = [];
        foreach ($modpacks as $modpack) {
            if ($modpack->private == 1 || $modpack->hidden == 1) {
                if (isset($this->client)) {
                    foreach ($this->client->modpacks as $pmodpack) {
                        if ($pmodpack->id == $modpack->id) {
                            $response['modpacks'][$modpack->slug] = $modpack->name;
                        }
                    }
                } else {
                    if (isset($this->key)) {
                        $response['modpacks'][$modpack->slug] = $modpack->name;
                    }
                }
            } else {
                $response['modpacks'][$modpack->slug] = $modpack->name;
            }
        }

        $response['mirror_url'] = Config::get('solder.mirror_url');

        return $response;
    }

    private function fetchModpack($slug)
    {
        $response = [];

        if (Cache::has('modpack.' . $slug) && empty($this->client) && empty($this->key)) {
            $modpack = Cache::get('modpack.' . $slug);
        } else {
            $modpack = Modpack::with('Builds')
                ->where("slug", "=", $slug)->first();
            if (empty($this->client) && empty($this->key)) {
                Cache::put('modpack.' . $slug, $modpack, now()->addMinutes(5));
            }
        }

        if (empty($modpack)) {
            return ["error" => "Modpack does not exist"];
        }

        $response['id'] = $modpack->id;
        $response['name'] = $modpack->slug;
        $response['display_name'] = $modpack->name;
        $response['url'] = $modpack->url;
        $response['icon'] = $modpack->icon_url;
        $response['icon_md5'] = $modpack->icon_md5;
        $response['logo'] = $modpack->logo_url;
        $response['logo_md5'] = $modpack->logo_md5;
        $response['background'] = $modpack->background_url;
        $response['background_md5'] = $modpack->background_md5;
        $response['recommended'] = $modpack->recommended;
        $response['latest'] = $modpack->latest;
        $response['builds'] = [];

        foreach ($modpack->builds as $build) {
            if ($build->is_published) {
                if (!$build->private || isset($this->key)) {
                    array_push($response['builds'], $build->version);
                } else {
                    if (isset($this->client)) {
                        foreach ($this->client->modpacks as $pmodpack) {
                            if ($modpack->id == $pmodpack->id) {
                                array_push($response['builds'], $build->version);
                            }
                        }
                    }
                }
            }
        }

        return $response;
    }

    private function fetchBuild($slug, $build)
    {
        $response = [];

        if (Cache::has('modpack.' . $slug) && empty($this->client) && empty($this->key)) {
            $modpack = Cache::Get('modpack.' . $slug);
        } else {
            $modpack = Modpack::where("slug", "=", $slug)->first();
            if (empty($this->client) && empty($this->key)) {
                Cache::put('modpack.' . $slug, $modpack, now()->addMinutes(5));
            }
        }

        if (empty($modpack)) {
            return ["error" => "Modpack does not exist"];
        }

        $buildpass = $build;
        if (Cache::has('modpack.' . $slug . '.build.' . $build) && empty($this->client) && empty($this->key)) {
            $build = Cache::get('modpack.' . $slug . '.build.' . $build);
        } else {
            $build = Build::with('Modversions')
                ->where("modpack_id", "=", $modpack->id)
                ->where("version", "=", $build)->first();
            if (empty($this->client) && empty($this->key)) {
                Cache::put('modpack.' . $slug . '.build.' . $buildpass, $build, now()->addMinutes(5));
            }
        }

        if (empty($build)) {
            return ["error" => "Build does not exist"];
        }

        $response['id'] = $build->id;
        $response['minecraft'] = $build->minecraft;
        $response['java'] = $build->min_java;
        $response['memory'] = $build->min_memory;
        $response['forge'] = $build->forge;
        $response['mods'] = [];

        if (!Request::has('include')) {
            if (Cache::has('modpack.' . $slug . '.build.' . $buildpass . 'modversion') && empty($this->client) && empty($this->key)) {
                $response['mods'] = Cache::get('modpack.' . $slug . '.build.' . $buildpass . 'modversion');
            } else {
                foreach ($build->modversions as $modversion) {
                    $response['mods'][] = [
                        "id" => $modversion->id,
                        "name" => $modversion->mod->name,
                        "version" => $modversion->version,
                        "md5" => $modversion->md5,
                        "filesize" => $modversion->filesize,
                        "url" => Config::get('solder.mirror_url') . 'mods/' . $modversion->mod->name . '/' . $modversion->mod->name . '-' . $modversion->version . '.zip'
                    ];
                }
                usort($response['mods'], function ($a, $b) {
                    return strcasecmp($a['name'], $b['name']);
                });
                Cache::put('modpack.' . $slug . '.build.' . $buildpass . 'modversion', $response['mods'], now()->addMinutes(5));
            }
        } else {
            if (Request::input('include') == "mods") {
                if (Cache::has('modpack.' . $slug . '.build.' . $buildpass . 'modversion.include.mods') && empty($this->client) && empty($this->key)) {
                    $response['mods'] = Cache::get('modpack.' . $slug . '.build.' . $buildpass . 'modversion.include.mods');
                } else {
                    foreach ($build->modversions as $modversion) {
                        $response['mods'][] = [
                            "id" => $modversion->id,
                            "name" => $modversion->mod->name,
                            "version" => $modversion->version,
                            "md5" => $modversion->md5,
                            "filesize" => $modversion->filesize,
                            "pretty_name" => $modversion->mod->pretty_name,
                            "author" => $modversion->mod->author,
                            "description" => $modversion->mod->description,
                            "link" => $modversion->mod->link,
                            "url" => Config::get('solder.mirror_url') . 'mods/' . $modversion->mod->name . '/' . $modversion->mod->name . '-' . $modversion->version . '.zip'
                        ];
                    }
                    usort($response['mods'], function ($a, $b) {
                        return strcasecmp($a['name'], $b['name']);
                    });
                    Cache::put('modpack.' . $slug . '.build.' . $buildpass . 'modversion.include.mods', $response['mods'], now()->addMinutes(5));
                }
            } else {
                $request = explode(",", Request::input('include'));
                if (Cache::has('modpack.' . $slug . '.build.' . $buildpass . 'modversion.include.' . $request) && empty($this->client) && empty($this->key)) {
                    $response['mods'] = Cache::get('modpack.' . $slug . '.build.' . $buildpass . 'modversion.include.' . $request);
                } else {
                    foreach ($build->modversions as $modversion) {
                        $data = [
                            "id" => $modversion->id,
                            "name" => $modversion->mod->name,
                            "version" => $modversion->version,
                            "md5" => $modversion->md5,
                            "filesize" => $modversion->filesize,
                        ];
                        $mod = (array) $modversion->mod;
                        $mod = $mod['attributes'];
                        foreach ($request as $type) {
                            if (isset($mod[$type])) {
                                $data[$type] = $mod[$type];
                            }
                        }

                        $response['mods'][] = $data;
                    }
                    usort($response['mods'], function ($a, $b) {
                        return strcasecmp($a['name'], $b['name']);
                    });
                    Cache::put('modpack.' . $slug . '.build.' . $buildpass . 'modversion.include.' . $request, $response['mods'],
                        now()->addMinutes(5));
                }
            }
        }

        return $response;
    }

}