use crate::can_io;
use crate::packets::types;

can_io! {
    struct ResourcePacksInfo {
        pub texture_pack_required: bool,
        pub has_scripts: bool,
        pub behaviour_packs: Vec<types::BehaviourPackInfo>,
        pub texture_packs: Vec<types::TexturePackInfo>,
        pub forcing_server_packs: bool,
    }
}
