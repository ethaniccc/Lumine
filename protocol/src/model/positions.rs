use crate::Little;
use std::convert::TryFrom;

pub struct Vec3 {
    pub x: f32,
    pub y: f32,
    pub z: f32
}

impl Vec3 {
    #[inline]
    pub fn as_array(&self) -> [f32; 3] {
        [self.x, self.y, self.z]
    }

    #[inline]
    pub fn as_vec(&self) -> Vec<f32> {
        vec![self.x, self.y, self.x]
    }
}

impl TryFrom<&[f32]> for Vec3 {
    type Error = std::io::Error;

    fn try_from(value: &[f32]) -> Result<Self, Self::Error> {
        Ok(
            Self {
                x: value[0],
                y: value[1],
                z: value[2]
            }
        )
    }
}

pub struct Vec2 {
    pub x: f32,
    pub y: f32
}

impl Vec2 {
    #[inline]
    pub fn as_array(&self) -> [f32; 2] {
        [self.x, self.y]
    }

    #[inline]
    pub fn as_vec(&self) -> Vec<f32> {
        vec![self.x, self.y]
    }
}

impl TryFrom<&[f32]> for Vec2 {
    type Error = std::io::Error;

    fn try_from(value: &[f32]) -> Result<Self, Self::Error> {
        Ok(
            Self {
                x: value[0],
                y: value[1]
            }
        )
    }
}